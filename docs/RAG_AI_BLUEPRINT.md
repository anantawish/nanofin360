# NanoFin360 RAG AI Blueprint

## Goal

Build a paid AI assistant that answers finance operation questions from the
client's own policies, SOPs, database reports, and documents.

The assistant should not replace approval policy or legal judgment. It should
retrieve, summarize, cite, and guide.

## Knowledge Sources

- product policy documents
- credit policy and scoring rules
- KYC checklist and compliance SOP
- branch operation manual
- collection and NPL workflow
- legal enforcement SOP
- contract templates and payment terms
- portfolio, installment, collection, and branch reports
- read-only database views created for AI retrieval

## Suggested Read-only Views

- `ai_customer_summary`
- `ai_contract_summary`
- `ai_installment_status`
- `ai_collection_queue`
- `ai_branch_portfolio`
- `ai_npl_risk_summary`
- `ai_compliance_cases`

These views should expose only fields needed for answers and should be filtered
by user role, branch, and permission.

## Example Questions

- Which branches have the highest overdue risk this month?
- Show contracts likely to become NPL next cycle.
- Summarize this customer's repayment behavior.
- What documents are missing for this KYC case?
- Which policy explains fee calculation for this product?
- What should a collection officer do after DPD 30?

## Guardrails

- Do not approve, reject, or restructure loans automatically.
- Always cite source documents or report snapshots.
- Use role and branch filters before retrieval.
- Redact ID card numbers, phone numbers, and addresses unless the user has
  explicit permission.
- Log AI questions and referenced sources.
- Keep model/API keys outside Git.

## Delivery Phases

### Phase 1: Document RAG

Upload SOPs, policies, product manuals, and templates. Answer with citations.

### Phase 2: Report RAG

Index generated portfolio, collection, compliance, and branch reports.

### Phase 3: Database RAG

Add read-only SQL views and permission-aware retrieval.

### Phase 4: Workflow AI

Add guided task suggestions, report drafting, and manager summaries.
