# KD Clinic Plugin — Contributor Pack

Welcome. This repo powers a dietitian workflow inside WordPress using WooCommerce, Fluent Forms, Fluent Booking, and FluentCRM. This pack is your quick map. Read it once and you can ship.

---

## 1) TLDR for new collaborators

**What this plugin does**
- Creates **Intakes** from Fluent Forms submissions. These are pre consultation profiles.
- Manages **Consultations** as a hierarchical CPT. The first consultation is the parent. Follow ups are children.
- Connects to **WooCommerce** for payments. Connects to **Fluent Booking** for paid appointments. Connects to **FluentCRM** for comms.
- Adds pricing helpers and checkout prefills where needed. Adds admin tables for rapid triage.

**How to start in 3 steps**
1. Clone the repo into `wp-content/plugins/kd-clinic` then activate **KD Clinic** in WP Admin.
2. Open the code map below. Pick a module inside `/includes/` and trace its hooks in `kd-clinic.php`.
3. Run through the two live flows in a staging site. Rejuvenate flow and Appointment flow. Screens are described in the Workflows section.

**What to work on**
- Features and fixes go in short branches from `main`. Each branch solves one issue.
- Use conventional commits. Example: `feat: add consultation follow up button`.
- Open a Pull Request. Describe what changed and how to test. Keep PRs small.

---

## 2) Repo layout

```
kd-clinic.php              # Plugin bootstrap. Loads modules and hooks.
/includes/
  admin-intakes.php        # Admin list and detail helpers for Intakes.
  admin-consultation.php   # Admin list and editing helpers for Consultations.
  cpt-intake.php           # Registers kd_intake CPT and its caps.
  cpt-consultation.php     # Registers kd_consultation CPT. Parent child follow ups.
  booking-prefill.php      # Prefills checkout and booking flows.
  checkout-prefill.php     # Woo checkout field prefills and links.
  hmo-pricing.php          # Pricing helpers for HMO rules.
  pricing-coupons.php      # Coupon and discount helpers.
  cart-hygiene.php         # Cart cleanup and order linking.
  community-lose.php       # Community program glue for Rejuvenate.
  defer-entries.php        # Defers heavy writes on form submit.
  intake-postpay.php       # Intake flows that complete payment after submit.
  helpers.php              # Common utilities, security, formatting.
/assets/                   # JS, CSS, images if present.
/languages/                # Translations when added.
```

---

## 3) Workflows

### A) Rejuvenate community service
Form in Fluent Forms → Intake record → WooCommerce checkout → Payment confirms enrollment.

**What you touch**
- `cpt-intake.php` for the CPT base.
- `admin-intakes.php` for admin table actions.
- `intake-postpay.php` and `checkout-prefill.php` for order linking.

### B) Appointment services (Lose A Dress and Nutrition Care)
Form in Fluent Forms → Intake record → Fluent Booking slot → Payment through WooCommerce → Parent Consultation created or started.

**What you touch**
- `booking-prefill.php` for handoff to booking.
- `cpt-consultation.php` for CPT and follow up hierarchy.
- `admin-consultation.php` for Start consultation and New follow up actions.

### C) Follow up consultations
From a parent Consultation open **New follow up**. Child inherits the Intake link. Timeline shows all consults.

**Quick fetch pattern**
- Use meta `_kd_intake_id` to find all consults for one patient.

---

## 4) Data model and keys

**CPTs**
- `kd_intake`  pre consultation profile.
- `kd_consultation`  session notes and plan. Children are follow ups.

**Common meta keys**
- `_kd_user_id`  WordPress user owner.
- `_kd_form_entry_id`  Fluent Forms entry id.
- `_kd_order_id`  WooCommerce order id when payment exists.
- `_kd_booking_id`  Fluent Booking appointment id.
- `_kd_service_type`  rejuvenate or appointment.
- `_kd_intake_id`  on consultations this links back to the intake.
- `_kd_status`  pending or completed or followup.
- `_kd_diet_plan`  structured array or JSON stored on consultation.

**Capabilities**
Define specific caps so roles can be tight.
- Intakes  `kd_intake_read`  `kd_intake_edit`  `kd_intake_export`.
- Consultations  `kd_consultation_read`  `kd_consultation_create`  `kd_consultation_edit`  `kd_consultation_followup`  `kd_consultation_export`.
- Settings  `kd_settings_read`  `kd_settings_manage`.

Set `map_meta_cap` true when registering CPTs. AAM can then assign access without broad admin powers.

---

## 5) Version history at a glance

**v3.2 → v7.6**
- Added Consultation CPT and admin tools.
- Added Deferred Entries and Intake Postpay modules.
- Introduced Pricing Coupons. Helpers expanded. Admin screens restructured.

**v7.6 → v8.6**
- Stabilization pass.
- Checkout Prefill added. Cart hygiene and coupons refactored. Admin screens cleaned.

Tags are `v3.2`, `v7.6`, `v8.6`. Use `git diff v3.2 v7.6` or `git diff v7.6 v8.6` to see the exact changes.

---

## 6) Getting started in under five minutes

**Local setup**
1. Clone into `wp-content/plugins/` then activate in WP Admin.
2. Create two test pages  one for Rejuvenate and one for Appointment. Attach your Fluent Forms and Fluent Booking setup.
3. Run one end to end test for each flow. Confirm you get an Intake and a Consultation when expected.

**Git workflow**
1. Pull `main` before you start.
2. Create a short branch for one task. Example `feat/followup-button`.
3. Commit with a clear message. Example `fix: prevent duplicate coupon add during checkout`.
4. Push and open a PR. Add steps to test. Keep the PR small and focused.

**Definition of done**
- No PHP notices. No fatal errors. Admin tables render. Intake to order link works in staging.
- You updated README or inline docs if the behavior changed.

---

## 7) Testing quick guide

- **Forms**  submit a Fluent Form and confirm a new Intake.
- **Booking**  confirm a Fluent Booking and check `_kd_booking_id` on the Intake.
- **Orders**  place a Woo order and confirm `_kd_order_id` is stored.
- **Consultations**  create a parent consult and at least one follow up. Confirm they share `_kd_intake_id`.

Optional unit tests can live in `/tests`. For now manual passes are fine.

---

## 8) Coding guidelines

- Follow WordPress coding standards for PHP. Escape output. Sanitize input.
- Use nonces for admin actions. Check capabilities on every action link.
- Keep functions small. Prefer pure helpers in `helpers.php` where possible.
- Avoid large PRs. Ship small and often.

---

## 9) Support and security

- Use GitHub Issues for bugs and feature requests.
- Report security concerns privately. Do not open a public issue with sensitive data.

---

## 10) Changelog summary

- **8.6**  Checkout Prefill added. Admin screens and pricing stabilized.
- **7.6**  Consultation CPT  Deferred Entries  Intake Postpay  Pricing Coupons introduced.
- **3.2**  Early stable with Intake CPT  pricing basics  checkout and community groundwork.

For full history read the tags in Git.

---

### Glossary
- **Intake**  patient profile collected before a session.
- **Consultation**  the face to face or remote session where notes and the plan are recorded.
- **Follow up**  a child consultation under the first session.
- **Rejuvenate**  community program paid at checkout.
- **Appointment**  services that use Fluent Booking before payment.

Ready to contribute. Open a branch and let us build.

