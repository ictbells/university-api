locals {
  vpc_id = data.aws_security_group.rds_primary.vpc_id

  public_subnet_ids = length(data.aws_subnets.vpc_public.ids) > 0 ? data.aws_subnets.vpc_public.ids : data.aws_subnets.vpc_all.ids

  instance_supported_azs = toset(data.aws_ec2_instance_type_offerings.instance_azs.locations)

  public_subnet_ids_for_instance_type = sort([
    for id, sn in data.aws_subnet.public_detail : id
    if contains(local.instance_supported_azs, sn.availability_zone)
  ])

  ec2_subnet_id = length(local.public_subnet_ids_for_instance_type) > 0 ? local.public_subnet_ids_for_instance_type[0] : null

  api_fqdn               = "${var.api_subdomain}.${var.hosted_zone_name}"
  staff_fqdn             = "${var.staff_subdomain}.${var.hosted_zone_name}"
  staff_www_fqdn         = "www.${var.staff_subdomain}.${var.hosted_zone_name}"
  student_fqdn           = "${var.student_subdomain}.${var.hosted_zone_name}"
  student_www_fqdn       = "www.${var.student_subdomain}.${var.hosted_zone_name}"
  app_url                = "https://${local.api_fqdn}"
  frontend_url_effective = var.frontend_url != "" ? var.frontend_url : "https://${local.staff_fqdn}"
  student_url_effective  = var.student_url != "" ? var.student_url : "https://${local.student_fqdn}"

  staff_bucket_name     = "${var.project_name}-staff-${data.aws_caller_identity.current.account_id}"
  student_bucket_name   = "${var.project_name}-student-${data.aws_caller_identity.current.account_id}"
  bootstrap_bucket_name = "${var.project_name}-bootstrap-${data.aws_caller_identity.current.account_id}"

  bootstrap_s3_key = "${var.environment}/ec2-bootstrap.sh"

  db_password_effective = var.db_password != "" ? var.db_password : random_password.db_app.result

  sanctum_domains = join(",", [
    local.staff_fqdn,
    local.staff_www_fqdn,
    local.student_fqdn,
    local.student_www_fqdn,
    local.api_fqdn,
  ])

  cors_origins = join(",", [
    local.frontend_url_effective,
    "https://${local.staff_www_fqdn}",
    local.student_url_effective,
    "https://${local.student_www_fqdn}",
  ])

  bootstrap_script = templatefile("${path.module}/templates/bootstrap.sh.tftpl", {
    region              = var.aws_region
    api_fqdn            = local.api_fqdn
    app_url             = local.app_url
    frontend_url        = local.frontend_url_effective
    student_url         = local.student_url_effective
    sanctum_domains     = local.sanctum_domains
    cors_origins        = local.cors_origins
    session_domain      = ".${var.hosted_zone_name}"
    session_cookie      = "bells_sis_session"
    letsencrypt_email   = var.letsencrypt_email
    rds_host            = data.aws_db_instance.existing.address
    rds_port            = tostring(data.aws_db_instance.existing.port)
    db_name             = var.db_name
    db_username         = var.db_username
    app_secret_name     = aws_secretsmanager_secret.app.name
    master_secret_name  = var.rds_master_secret_name
    app_key_secret_name = aws_secretsmanager_secret.app_key.name
    env_s3_bucket       = aws_s3_bucket.bootstrap.id
    env_s3_key          = "api/.env"
  })

  bootstrap_script_md5 = md5(local.bootstrap_script)

  spa_acm_sans = [
    local.staff_www_fqdn,
    local.student_fqdn,
    local.student_www_fqdn,
  ]
}

resource "random_password" "db_app" {
  length           = 32
  special          = true
  override_special = "!#$%&*()-_=+[]{}<>:?"
}

resource "random_password" "app_key_bytes" {
  length  = 32
  special = false
}
