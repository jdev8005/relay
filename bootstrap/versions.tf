terraform {
  required_version = ">= 1.11"

  backend "s3" {
    bucket       = "relay-tfstate-591312851548"
    key          = "bootstrap/terraform.tfstate"
    region       = "us-east-2"
    encrypt      = true
    kms_key_id   = "arn:aws:kms:us-east-2:591312851548:key/d090d434-1d4c-4b40-96c7-796260c04bb5"
    use_lockfile = true
  }

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.0"
    }
  }

}

provider "aws" {
  region              = var.region
  allowed_account_ids = ["591312851548"] # your personal account ID

  default_tags {
    tags = {
      Project     = "relay"
      ManagedBy   = "terraform"
      Environment = "shared"
      Repo        = "github.com/${var.github_owner}/${var.github_repo}"
    }
  }
}
