# API only — never create/change api.cycbankease.com (BankEase owns that A record).
resource "aws_route53_record" "api_a" {
  zone_id         = data.aws_route53_zone.public.zone_id
  name            = local.api_fqdn
  type            = "A"
  ttl             = 300
  allow_overwrite = true
  records         = [aws_eip.api.public_ip]
}

resource "aws_route53_record" "staff_a" {
  zone_id         = data.aws_route53_zone.public.zone_id
  name            = local.staff_fqdn
  type            = "A"
  allow_overwrite = true

  alias {
    name                   = aws_cloudfront_distribution.staff.domain_name
    zone_id                = aws_cloudfront_distribution.staff.hosted_zone_id
    evaluate_target_health = false
  }
}

resource "aws_route53_record" "staff_aaaa" {
  zone_id         = data.aws_route53_zone.public.zone_id
  name            = local.staff_fqdn
  type            = "AAAA"
  allow_overwrite = true

  alias {
    name                   = aws_cloudfront_distribution.staff.domain_name
    zone_id                = aws_cloudfront_distribution.staff.hosted_zone_id
    evaluate_target_health = false
  }
}

resource "aws_route53_record" "staff_www_a" {
  zone_id         = data.aws_route53_zone.public.zone_id
  name            = local.staff_www_fqdn
  type            = "A"
  allow_overwrite = true

  alias {
    name                   = aws_cloudfront_distribution.staff.domain_name
    zone_id                = aws_cloudfront_distribution.staff.hosted_zone_id
    evaluate_target_health = false
  }
}

resource "aws_route53_record" "staff_www_aaaa" {
  zone_id         = data.aws_route53_zone.public.zone_id
  name            = local.staff_www_fqdn
  type            = "AAAA"
  allow_overwrite = true

  alias {
    name                   = aws_cloudfront_distribution.staff.domain_name
    zone_id                = aws_cloudfront_distribution.staff.hosted_zone_id
    evaluate_target_health = false
  }
}

resource "aws_route53_record" "student_a" {
  zone_id         = data.aws_route53_zone.public.zone_id
  name            = local.student_fqdn
  type            = "A"
  allow_overwrite = true

  alias {
    name                   = aws_cloudfront_distribution.student.domain_name
    zone_id                = aws_cloudfront_distribution.student.hosted_zone_id
    evaluate_target_health = false
  }
}

resource "aws_route53_record" "student_aaaa" {
  zone_id         = data.aws_route53_zone.public.zone_id
  name            = local.student_fqdn
  type            = "AAAA"
  allow_overwrite = true

  alias {
    name                   = aws_cloudfront_distribution.student.domain_name
    zone_id                = aws_cloudfront_distribution.student.hosted_zone_id
    evaluate_target_health = false
  }
}

resource "aws_route53_record" "student_www_a" {
  zone_id         = data.aws_route53_zone.public.zone_id
  name            = local.student_www_fqdn
  type            = "A"
  allow_overwrite = true

  alias {
    name                   = aws_cloudfront_distribution.student.domain_name
    zone_id                = aws_cloudfront_distribution.student.hosted_zone_id
    evaluate_target_health = false
  }
}

resource "aws_route53_record" "student_www_aaaa" {
  zone_id         = data.aws_route53_zone.public.zone_id
  name            = local.student_www_fqdn
  type            = "AAAA"
  allow_overwrite = true

  alias {
    name                   = aws_cloudfront_distribution.student.domain_name
    zone_id                = aws_cloudfront_distribution.student.hosted_zone_id
    evaluate_target_health = false
  }
}
