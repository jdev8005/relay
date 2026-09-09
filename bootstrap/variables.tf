variable "region" {
  type    = string
  default = "us-east-2"
}

variable "github_owner" {
  type        = string
  description = "GitHub username or org that owns the Relay repo"
  default     = "jdev8005"
}

variable "github_repo" {
  type    = string
  default = "relay"
}

variable "budget_limit_usd" {
  type    = string
  default = "0.01"
}

variable "alert_email" {
  type        = string
  description = "Where budget notifications go"
  default     = "jshinn0587@gmail.com"
}
