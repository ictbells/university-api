resource "aws_cloudfront_function" "spa_index" {
  name    = "${var.environment}-bells-sis-spa-index"
  runtime = "cloudfront-js-1.0"
  comment = "Rewrite extensionless SPA routes to /index.html; leave hashed assets alone"
  publish = true
  code    = file("${path.module}/cloudfront_spa.js")
}

resource "aws_cloudfront_distribution" "staff" {
  enabled             = true
  is_ipv6_enabled     = true
  comment             = "${var.environment} staff SPA ${local.staff_fqdn}"
  price_class         = var.cloudfront_price_class
  default_root_object = "index.html"
  aliases             = [local.staff_fqdn, local.staff_www_fqdn]

  origin {
    domain_name              = aws_s3_bucket.staff.bucket_regional_domain_name
    origin_id                = "s3-staff"
    origin_access_control_id = aws_cloudfront_origin_access_control.staff.id
  }

  # HTML and client routes: always revalidate. Do not map 403/404 to index.html
  # (that served text/html for missing .js and broke module scripts).
  default_cache_behavior {
    allowed_methods        = ["GET", "HEAD", "OPTIONS"]
    cached_methods         = ["GET", "HEAD"]
    target_origin_id       = "s3-staff"
    compress               = true
    viewer_protocol_policy = "redirect-to-https"
    cache_policy_id        = data.aws_cloudfront_cache_policy.caching_disabled.id

    function_association {
      event_type   = "viewer-request"
      function_arn = aws_cloudfront_function.spa_index.arn
    }
  }

  ordered_cache_behavior {
    path_pattern           = "/assets/*"
    allowed_methods        = ["GET", "HEAD", "OPTIONS"]
    cached_methods         = ["GET", "HEAD"]
    target_origin_id       = "s3-staff"
    compress               = true
    viewer_protocol_policy = "redirect-to-https"
    cache_policy_id        = data.aws_cloudfront_cache_policy.caching_optimized.id
  }

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  viewer_certificate {
    acm_certificate_arn      = aws_acm_certificate_validation.spa.certificate_arn
    ssl_support_method       = "sni-only"
    minimum_protocol_version = "TLSv1.2_2021"
  }

  tags = {
    Name = "${var.environment}-bells-sis-staff-cf"
  }
}

resource "aws_cloudfront_distribution" "student" {
  enabled             = true
  is_ipv6_enabled     = true
  comment             = "${var.environment} student SPA ${local.student_fqdn}"
  price_class         = var.cloudfront_price_class
  default_root_object = "index.html"
  aliases             = [local.student_fqdn, local.student_www_fqdn]

  origin {
    domain_name              = aws_s3_bucket.student.bucket_regional_domain_name
    origin_id                = "s3-student"
    origin_access_control_id = aws_cloudfront_origin_access_control.student.id
  }

  default_cache_behavior {
    allowed_methods        = ["GET", "HEAD", "OPTIONS"]
    cached_methods         = ["GET", "HEAD"]
    target_origin_id       = "s3-student"
    compress               = true
    viewer_protocol_policy = "redirect-to-https"
    cache_policy_id        = data.aws_cloudfront_cache_policy.caching_disabled.id

    function_association {
      event_type   = "viewer-request"
      function_arn = aws_cloudfront_function.spa_index.arn
    }
  }

  ordered_cache_behavior {
    path_pattern           = "/assets/*"
    allowed_methods        = ["GET", "HEAD", "OPTIONS"]
    cached_methods         = ["GET", "HEAD"]
    target_origin_id       = "s3-student"
    compress               = true
    viewer_protocol_policy = "redirect-to-https"
    cache_policy_id        = data.aws_cloudfront_cache_policy.caching_optimized.id
  }

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  viewer_certificate {
    acm_certificate_arn      = aws_acm_certificate_validation.spa.certificate_arn
    ssl_support_method       = "sni-only"
    minimum_protocol_version = "TLSv1.2_2021"
  }

  tags = {
    Name = "${var.environment}-bells-sis-student-cf"
  }
}
