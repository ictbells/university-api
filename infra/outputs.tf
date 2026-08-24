output "api_fqdn" {
  value       = local.api_fqdn
  description = "API hostname (HTTPS via nginx + Let's Encrypt)."
}

output "api_eip" {
  value       = aws_eip.api.public_ip
  description = "Elastic IP for the API EC2 instance."
}

output "github_deploy_role_arn" {
  value       = length(aws_iam_role.github_deploy) > 0 ? aws_iam_role.github_deploy[0].arn : ""
  description = "Set as GitHub secret AWS_ROLE_ARN for SSM deploy (no SSH key)."
}

output "deploy_s3_bucket" {
  value       = aws_s3_bucket.bootstrap.id
  description = "S3 bucket for SSM deploy artifacts (prefix deploys/api/)."
}

output "api_instance_id" {
  value       = aws_instance.api.id
  description = "EC2 instance id (SSM deploy + aws ssm start-session)."
}

output "rds_endpoint" {
  value       = data.aws_db_instance.existing.address
  description = "Existing RDS hostname (prod-bankease)."
}

output "db_name" {
  value       = var.db_name
  description = "SIS MySQL schema name (not bankease)."
}

output "app_secret_arn" {
  value       = aws_secretsmanager_secret.app.arn
  description = "Secrets Manager ARN for DB app credentials."
}

output "staff_url" {
  value       = local.frontend_url_effective
  description = "Canonical staff SPA URL."
}

output "student_url" {
  value       = local.student_url_effective
  description = "Canonical student SPA URL."
}

output "staff_bucket" {
  value       = aws_s3_bucket.staff.id
  description = "S3 bucket for staff SPA dist/."
}

output "student_bucket" {
  value       = aws_s3_bucket.student.id
  description = "S3 bucket for student SPA dist/."
}

output "staff_cloudfront_id" {
  value       = aws_cloudfront_distribution.staff.id
  description = "CloudFront distribution id for staff SPA."
}

output "student_cloudfront_id" {
  value       = aws_cloudfront_distribution.student.id
  description = "CloudFront distribution id for student SPA."
}

output "staff_aliases" {
  value       = [local.staff_fqdn, local.staff_www_fqdn]
  description = "CloudFront aliases for staff."
}

output "student_aliases" {
  value       = [local.student_fqdn, local.student_www_fqdn]
  description = "CloudFront aliases for student."
}
