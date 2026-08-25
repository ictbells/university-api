# Optional GitHub Actions OIDC role for SSM deploy (no SSH key).
# CI credentials (access keys / OIDC) are separate from this AWS IAM OIDC provider.

locals {
  github_deploy_enabled = var.enable_github_deploy_role && var.github_repository != ""
}

resource "aws_iam_openid_connect_provider" "github" {
  count = local.github_deploy_enabled && var.create_github_oidc_provider ? 1 : 0

  url             = "https://token.actions.githubusercontent.com"
  client_id_list  = ["sts.amazonaws.com"]
  thumbprint_list = ["6938fd4d98bab03fa021e5087f35c2eb286b9550"]
}

data "aws_iam_openid_connect_provider" "github" {
  count = local.github_deploy_enabled && !var.create_github_oidc_provider ? 1 : 0
  url   = "https://token.actions.githubusercontent.com"
}

locals {
  github_oidc_provider_arn = local.github_deploy_enabled ? (
    var.create_github_oidc_provider ? aws_iam_openid_connect_provider.github[0].arn : data.aws_iam_openid_connect_provider.github[0].arn
  ) : ""
}

resource "aws_iam_role" "github_deploy" {
  count = local.github_deploy_enabled ? 1 : 0
  name  = "${var.environment}-bells-sis-github-deploy"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect = "Allow"
      Principal = {
        Federated = local.github_oidc_provider_arn
      }
      Action = "sts:AssumeRoleWithWebIdentity"
      Condition = {
        StringEquals = {
          "token.actions.githubusercontent.com:aud" = "sts.amazonaws.com"
        }
        StringLike = {
          "token.actions.githubusercontent.com:sub" = "repo:${var.github_repository}:*"
        }
      }
    }]
  })
}

resource "aws_iam_role_policy" "github_deploy" {
  count = local.github_deploy_enabled ? 1 : 0
  name  = "${var.environment}-bells-sis-github-deploy"
  role  = aws_iam_role.github_deploy[0].id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid    = "UploadDeployArtifacts"
        Effect = "Allow"
        Action = [
          "s3:PutObject",
          "s3:GetObject",
          "s3:AbortMultipartUpload"
        ]
        Resource = "${aws_s3_bucket.bootstrap.arn}/deploys/*"
      },
      {
        Sid    = "ReadAppEnvOverlay"
        Effect = "Allow"
        Action = [
          "s3:GetObject",
          "s3:GetObjectVersion"
        ]
        Resource = [
          "${aws_s3_bucket.bootstrap.arn}/api/.env",
          "${aws_s3_bucket.bootstrap.arn}/env/*"
        ]
      },
      {
        Sid    = "ListDeployPrefix"
        Effect = "Allow"
        Action = [
          "s3:ListBucket",
          "s3:GetBucketLocation"
        ]
        Resource = aws_s3_bucket.bootstrap.arn
        Condition = {
          StringLike = {
            "s3:prefix" = ["deploys", "deploys/*", "api", "api/*", "env", "env/*"]
          }
        }
      },
      {
        Sid    = "RunShellOnApiInstance"
        Effect = "Allow"
        Action = [
          "ssm:SendCommand"
        ]
        Resource = [
          "arn:aws:ssm:${var.aws_region}::document/AWS-RunShellScript",
          "arn:aws:ec2:${var.aws_region}:${data.aws_caller_identity.current.account_id}:instance/${aws_instance.api.id}"
        ]
      },
      {
        Sid    = "ReadCommandOutput"
        Effect = "Allow"
        Action = [
          "ssm:GetCommandInvocation",
          "ssm:ListCommandInvocations"
        ]
        Resource = "*"
      },
      {
        Sid    = "DescribeInstanceForDeploy"
        Effect = "Allow"
        Action = [
          "ec2:DescribeInstances"
        ]
        Resource = "*"
      },
      {
        Sid    = "SyncSpaBuckets"
        Effect = "Allow"
        Action = [
          "s3:PutObject",
          "s3:DeleteObject",
          "s3:ListBucket",
          "s3:GetObject"
        ]
        Resource = [
          aws_s3_bucket.staff.arn,
          "${aws_s3_bucket.staff.arn}/*",
          aws_s3_bucket.student.arn,
          "${aws_s3_bucket.student.arn}/*"
        ]
      },
      {
        Sid    = "InvalidateCloudFront"
        Effect = "Allow"
        Action = [
          "cloudfront:CreateInvalidation",
          "cloudfront:GetInvalidation"
        ]
        Resource = [
          aws_cloudfront_distribution.staff.arn,
          aws_cloudfront_distribution.student.arn
        ]
      }
    ]
  })
}
