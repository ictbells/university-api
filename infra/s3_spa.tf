resource "aws_s3_bucket" "staff" {
  bucket = local.staff_bucket_name

  tags = {
    Name = "${var.environment}-bells-sis-staff"
  }
}

resource "aws_s3_bucket" "student" {
  bucket = local.student_bucket_name

  tags = {
    Name = "${var.environment}-bells-sis-student"
  }
}

resource "aws_s3_bucket_public_access_block" "staff" {
  bucket = aws_s3_bucket.staff.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_public_access_block" "student" {
  bucket = aws_s3_bucket.student.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_ownership_controls" "staff" {
  bucket = aws_s3_bucket.staff.id

  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

resource "aws_s3_bucket_ownership_controls" "student" {
  bucket = aws_s3_bucket.student.id

  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "staff" {
  bucket = aws_s3_bucket.staff.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "student" {
  bucket = aws_s3_bucket.student.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_cloudfront_origin_access_control" "staff" {
  name                              = "${var.environment}-bells-sis-staff-oac"
  description                       = "Sign CloudFront requests to staff SPA bucket"
  origin_access_control_origin_type = "s3"
  signing_behavior                  = "always"
  signing_protocol                  = "sigv4"
}

resource "aws_cloudfront_origin_access_control" "student" {
  name                              = "${var.environment}-bells-sis-student-oac"
  description                       = "Sign CloudFront requests to student SPA bucket"
  origin_access_control_origin_type = "s3"
  signing_behavior                  = "always"
  signing_protocol                  = "sigv4"
}

resource "aws_s3_object" "staff_index" {
  bucket        = aws_s3_bucket.staff.id
  key           = "index.html"
  content_type  = "text/html; charset=utf-8"
  cache_control = "no-cache, must-revalidate"
  content       = <<-HTML
    <!doctype html>
    <html lang="en"><head><meta charset="utf-8"><title>Staff portal</title></head>
    <body>
      <p>Staff SPA is not uploaded yet. Run the frontend deploy workflow (AWS target).</p>
    </body></html>
  HTML

  lifecycle {
    ignore_changes = [content, etag, content_type]
  }
}

resource "aws_s3_object" "student_index" {
  bucket        = aws_s3_bucket.student.id
  key           = "index.html"
  content_type  = "text/html; charset=utf-8"
  cache_control = "no-cache, must-revalidate"
  content       = <<-HTML
    <!doctype html>
    <html lang="en"><head><meta charset="utf-8"><title>Student portal</title></head>
    <body>
      <p>Student SPA is not uploaded yet. Run the student deploy workflow (AWS target).</p>
    </body></html>
  HTML

  lifecycle {
    ignore_changes = [content, etag, content_type]
  }
}

resource "aws_s3_bucket_policy" "staff_oac" {
  bucket = aws_s3_bucket.staff.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Sid    = "AllowCloudFrontServicePrincipalRead"
      Effect = "Allow"
      Principal = {
        Service = "cloudfront.amazonaws.com"
      }
      Action   = "s3:GetObject"
      Resource = "${aws_s3_bucket.staff.arn}/*"
      Condition = {
        StringEquals = {
          "AWS:SourceArn" = aws_cloudfront_distribution.staff.arn
        }
      }
    }]
  })

  depends_on = [aws_s3_bucket_public_access_block.staff]
}

resource "aws_s3_bucket_policy" "student_oac" {
  bucket = aws_s3_bucket.student.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Sid    = "AllowCloudFrontServicePrincipalRead"
      Effect = "Allow"
      Principal = {
        Service = "cloudfront.amazonaws.com"
      }
      Action   = "s3:GetObject"
      Resource = "${aws_s3_bucket.student.arn}/*"
      Condition = {
        StringEquals = {
          "AWS:SourceArn" = aws_cloudfront_distribution.student.arn
        }
      }
    }]
  })

  depends_on = [aws_s3_bucket_public_access_block.student]
}
