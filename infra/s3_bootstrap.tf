resource "aws_s3_bucket" "bootstrap" {
  bucket = local.bootstrap_bucket_name

  tags = {
    Name = "${var.environment}-bells-sis-bootstrap"
  }
}

resource "aws_s3_bucket_public_access_block" "bootstrap" {
  bucket = aws_s3_bucket.bootstrap.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_server_side_encryption_configuration" "bootstrap" {
  bucket = aws_s3_bucket.bootstrap.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_object" "ec2_bootstrap" {
  bucket       = aws_s3_bucket.bootstrap.id
  key          = local.bootstrap_s3_key
  content      = local.bootstrap_script
  content_type = "text/x-shellscript"
  etag         = md5(local.bootstrap_script)
}
