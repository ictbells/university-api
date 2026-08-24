variable "aws_region" {
  type        = string
  description = "Region for EC2 / RDS lookup / S3. Must match prod-bankease (us-east-1)."
  default     = "us-east-1"
}

variable "environment" {
  type        = string
  description = "Environment label for tagging and resource names."
  default     = "test"
}

variable "project_name" {
  type        = string
  description = "Short project tag value."
  default     = "bells-sis"
}

variable "hosted_zone_name" {
  type        = string
  description = "Existing Route 53 public hosted zone (apex)."
  default     = "cycbankease.com"
}

variable "api_subdomain" {
  type        = string
  description = "API hostname label. Must NOT be 'api' (api.cycbankease.com is BankEase)."
  default     = "bells-api"

  validation {
    condition     = var.api_subdomain != "api"
    error_message = "api_subdomain must not be 'api' — that record already belongs to BankEase."
  }
}

variable "staff_subdomain" {
  type        = string
  description = "Staff SPA hostname label (staff.<zone>)."
  default     = "staff"
}

variable "student_subdomain" {
  type        = string
  description = "Student SPA hostname label (student.<zone>)."
  default     = "student"
}

variable "letsencrypt_email" {
  type        = string
  description = "Email for Let's Encrypt (certbot) on the API host."
  default     = "oluropoadewale@gmail.com"
}

variable "instance_type" {
  type        = string
  description = "EC2 instance type for the Laravel API."
  default     = "t2.micro"
}

variable "rds_instance_identifier" {
  type        = string
  description = "Existing RDS instance identifier to attach to (do not create)."
  default     = "prod-bankease"
}

variable "rds_master_secret_name" {
  type        = string
  description = "Secrets Manager secret used to CREATE DATABASE / GRANT for the SIS schema. Empty skips schema bootstrap."
  default     = "prod/bankease/rds/master"
}

variable "db_name" {
  type        = string
  description = "MySQL schema for this SIS API. Must not be 'bankease'."
  default     = "bells_sis"

  validation {
    condition     = var.db_name != "bankease"
    error_message = "db_name must not be 'bankease' — that schema belongs to BankEase."
  }
}

variable "db_username" {
  type        = string
  description = "App DB username created on the existing RDS for this SIS."
  default     = "bells_sis_app"
}

variable "db_password" {
  type        = string
  description = "App DB password. Leave empty to auto-generate and store in Secrets Manager."
  default     = ""
  sensitive   = true
}

variable "app_key" {
  type        = string
  description = "Laravel APP_KEY (base64:...). Leave empty to generate a placeholder; replace after first deploy if needed."
  default     = ""
  sensitive   = true
}

variable "frontend_url" {
  type        = string
  description = "Canonical staff SPA URL for CORS / FRONTEND_URL."
  default     = ""
}

variable "student_url" {
  type        = string
  description = "Canonical student SPA URL for CORS / STUDENT_URL."
  default     = ""
}

variable "ssh_ingress_cidr" {
  type        = string
  description = "Optional SSH CIDR. Empty = SSM only (no port 22)."
  default     = ""
}

variable "key_name" {
  type        = string
  description = "Optional EC2 key pair name. Empty = SSM Session Manager only."
  default     = ""
}

variable "cloudfront_price_class" {
  type        = string
  description = "CloudFront price class."
  default     = "PriceClass_100"
}

variable "root_volume_gb" {
  type        = number
  description = "Encrypted gp3 root volume size in GiB."
  default     = 20
}

variable "enable_github_deploy_role" {
  type        = bool
  description = "Create IAM role for GitHub Actions to deploy via SSM + S3."
  default     = true
}

variable "create_github_oidc_provider" {
  type        = bool
  description = "Create the account GitHub OIDC provider. Set false if token.actions.githubusercontent.com already exists (manage elsewhere)."
  default     = true
}

variable "github_repository" {
  type        = string
  description = "GitHub repo allowed to assume the deploy role (org/name). Empty skips deploy role."
  default     = ""
}
