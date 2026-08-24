resource "aws_acm_certificate" "spa" {
  provider = aws.us_east_1

  domain_name               = local.staff_fqdn
  validation_method         = "DNS"
  subject_alternative_names = local.spa_acm_sans

  lifecycle {
    create_before_destroy = true
  }

  tags = {
    Name = "${var.environment}-bells-sis-spa"
  }
}

resource "aws_route53_record" "spa_cert_validation" {
  for_each = {
    for dvo in aws_acm_certificate.spa.domain_validation_options : dvo.domain_name => {
      name   = dvo.resource_record_name
      record = dvo.resource_record_value
      type   = dvo.resource_record_type
    }
  }

  allow_overwrite = true
  name            = each.value.name
  records         = [each.value.record]
  ttl             = 60
  type            = each.value.type
  zone_id         = data.aws_route53_zone.public.zone_id
}

resource "aws_acm_certificate_validation" "spa" {
  provider = aws.us_east_1

  certificate_arn         = aws_acm_certificate.spa.arn
  validation_record_fqdns = [for r in aws_route53_record.spa_cert_validation : r.fqdn]
}
