# NanoFin360 Public Edition

## Purpose

NanoFin360 Public Edition is the free inspection and demo version of the
finance/leasing platform. It should make the product easy to trust, easy to
install, and safe to share publicly.

The public repository is not the full paid service. It is the entry point for
custom implementation work.

## What Is Included

- PHP and MySQL application baseline
- schema-only database setup
- core branch finance modules
- customer, KYC, scoring, affordability, installment, collection, and reporting
  workflows
- sanitized sample-ready structure
- public documentation for installation and review

## What Is Not Included

- production customer data
- real credentials, API keys, or OCR keys
- client-specific scoring rules
- client-specific contract templates
- private deployment scripts
- managed backup and monitoring
- RAG AI production knowledge base

## Public Safety Rules

- Keep only schema or demo-safe data in Git.
- Keep credentials in environment variables.
- Do not commit client documents, ID cards, contracts, statements, or OCR
  output.
- Keep paid client customization in private branches or separate private repos.
- Use demo data when presenting the public edition.

## Conversion Path

1. Prospect installs or reviews the public edition.
2. We run a discovery session for branch process, loan products, scoring rules,
   collections, reporting, and compliance.
3. We deploy a private client instance.
4. We migrate data and configure roles.
5. We add custom workflows and dashboards.
6. We add RAG AI for policies, portfolio questions, branch SOPs, and executive
   summaries.
