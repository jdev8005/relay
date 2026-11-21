resource "aws_security_group" "alb" {
  name        = "relay-alb"
  description = "Public entry point"
  vpc_id      = aws_vpc.main.id

  ingress {
    description = "HTTPS from anywhere"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  # VPC CIDR rather than the app SG: referencing each other would create a
  # dependency cycle with inline rules. See docs/adr/0017.
  egress {
    description = "To app tasks inside the VPC only"
    from_port   = 8080
    to_port     = 8080
    protocol    = "tcp"
    cidr_blocks = [aws_vpc.main.cidr_block]
  }

  tags = { Name = "relay-alb" }
}

# App egress to 0.0.0.0/0 is accepted: without VPC endpoints (ADR-0013),
# ECR, CloudWatch Logs and partner APIs are reached at public IPs that
# can't be enumerated in a security group. See docs/adr/0017.
#trivy:ignore:AWS-0104
resource "aws_security_group" "app" {
  name        = "relay-app"
  description = "ECS tasks"
  vpc_id      = aws_vpc.main.id

  # Source is the ALB's security group, not a CIDR, so only the load
  # balancer can reach the tasks, even from inside the VPC.
  ingress {
    description     = "HTTP from the load balancer only"
    from_port       = 8080
    to_port         = 8080
    protocol        = "tcp"
    security_groups = [aws_security_group.alb.id]
  }

  egress {
    description = "Outbound via NAT"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = { Name = "relay-app" }
}
