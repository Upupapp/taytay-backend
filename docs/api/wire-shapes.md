# Wire shapes — the payload field names, extracted from the code that builds them

**Why this file exists.** `openapi.json` publishes 221 paths and 56 schemas, and **52 of those
schemas are enums** — the other four are `Error`, `Meta`, `PaginatedMeta` and `Pagination`. Not one
resource shape is published. Every response documents `data` as an untyped object, so a client
generating from the document receives the envelope, the error vocabulary and the enums, and
**nothing at all about what comes back inside `data`**.

That contradicts the first acceptance criterion `ApiContractTest` states for itself — *"a frontend
developer can build without reading backend code."* Today they cannot: the field names live in
private `*Projection()` methods inside controllers, and the only way to learn them is to open the
PHP.

This file is the interim answer. It is **extracted from those projection methods**, so it is
measured rather than transcribed, and it gives the four client teams the `snake_case` names their
mappers need. It is not a substitute for publishing the shapes properly — that is TAB 06's work,
where the generated types become a build artefact the console vendors with a source SHA, and a
backend field rename becomes a compile error in the client rather than a runtime surprise.

**Read it as names, not types.** The extraction reports the keys each projection emits. Nullability
and value types still come from the code.

### `Audit/AuditController::projection` — 15 fields
`id, occurred_at, actor_subject_id, actor_account_type, action, risk, entity_type, entity_id, summary, changed_fields, reason, request_id, client_channel, ip_address, user_agent`

### `Audit/GovernanceController::consentProjection` — 6 fields
`id, purpose, granted_at, withdrawn_at, is_live, notice_version`

### `Audit/GovernanceController::holdProjection` — 11 fields
`id, entity_type, entity_id, subject_id, reference, reason, placed_by, placed_at, lifted_at, lift_reason, is_active`

### `Content/EngagementController::readerProjection` — 8 fields
`id, parent_id, body, is_official, is_mine, author_subject_id, created_at, edited_at`

### `Content/EngagementController::moderatorProjection` — 4 fields
`moderation_state, moderation_reason, moderated_by, moderated_at`

### `Content/NewsfeedController::adminProjection` — 7 fields
`status, author_subject_id, audience, audience_barangay_id, scheduled_for, archived_at, available_transitions`

### `Content/NewsfeedController::publicProjection` — 8 fields
`id, headline, body, category, is_pinned, comments_enabled, published_at, media`

### `Events/EventController::adminProjection` — 4 fields
`status, author_subject_id, published_at, available_transitions`

### `Events/EventController::publicProjection` — 23 fields
`id, slug, title, summary, description, category, cover_file_id, cover_urls, cover_alt_text, starts_at, ends_at, timezone, venue_name, venue_address, map_url, contact_office, contact_person, contact_number, participation_note, participant_instructions, registration, is_cancelled, cancellation_reason`

### `Events/EventRegistrationController::citizenProjection` — 9 fields
`id, reference, status, registered_at, promoted_at, attendance, cancelled_at, cancellation_reason, event`

### `Events/EventRegistrationController::staffProjection` — 15 fields
`id, reference, resident_id, resident_name, status, registered_at, promoted_at, attendance, attendance_marked_at, attendance_marked_by, source_channel, staff_notes, cancelled_at, cancelled_by, cancellation_reason`

### `Notification/MyNotificationController::projection` — 10 fields
`id, type, title, body, subject_type, subject_id, priority, category, read_at, created_at`

### `Reporting/ReportController::projection` — 12 fields
`id, report, format, filters, status, is_person_level, row_count, requested_at, completed_at, expires_at, is_downloadable, failure_reason`

### `ResidentProfile/HouseholdController::listProjection` — 8 fields
`id, code, barangay_id, street_address, purok_or_sitio, member_count, verification_status, status`

### `ResidentProfile/HouseholdController::detailProjection` — 11 fields
`dwelling_type, tenure_status, electricity_source, water_source, toilet_facility, profile_completeness, status_reason, verified_at, head, members, families`

### `ResidentProfile/HouseholdController::familyProjection` — 8 fields
`id, code, label, household_id, head, member_count, verification_status, status`

### `ResidentProfile/HouseholdController::membershipProjection` — 6 fields
`id, household_id, resident, effective_from, effective_to, end_reason`

### `ResidentProfile/KycController::applicantProjection` — 7 fields
`id, status, can_edit, submitted_at, message, claimed, resident_id`

### `ResidentProfile/KycController::reviewerProjection` — 10 fields
`id, status, account_id, claimed_name, claimed_birth_date, claimed_barangay_id, submitted_at, reviewed_at, resident_id, undecided_candidates`

### `ResidentProfile/MyProfileController::profileProjection` — 15 fields
`id, first_name, middle_name, last_name, suffix, sex, birth_date, civil_status, barangay_id, street_address, purok_or_sitio, mobile_number, email, verification_tier, is_active`

### `ResidentProfile/MyProfileController::correctionProjection` — 7 fields
`id, status, note, review_note, reviewed_at, created_at, changes`

### `ResidentProfile/RelationshipController::projection` — 9 fields
`id, type, derived, resident_id, related_resident_id, effective_from, effective_to, end_reason, note`

### `ResidentProfile/ResidentController::listProjection` — 6 fields
`id, name, birth_date, barangay_id, verification_tier, is_active`

### `ResidentProfile/ResidentController::detailProjection` — 13 fields
`first_name, middle_name, last_name, suffix, sex, civil_status, street_address, purok_or_sitio, mobile_number, email, verified_at, created_at, updated_at`

### `ResidentProfile/ResidentController::linkProjection` — 9 fields
`id, account_id, origin, status, linked_by, linked_at, revoked_by, revoked_at, revocation_reason`

### `ResidentProfile/ResidentCorrectionController::reviewerProjection` — 10 fields
`id, status, note, review_note, requested_by, reviewed_by, reviewed_at, created_at, resident, changes`

### `ResidentProfile/ResidentDuplicateController::sideProjection` — 6 fields
`id, name, birth_date, barangay_id, verification_tier, is_active`

### `ResidentProfile/ResidentDuplicateController::mergeProjection` — 6 fields
`id, survivor_resident_id, absorbed_resident_id, reason, reassigned, merged_at`

### `ResidentProfile/VulnerabilityController::factorProjection` — 14 fields
`id, factor_code, label, status, severity, source, is_protected, observed_at, effective_from, effective_to, end_reason, reviewed_at, note, counts_toward_score`

### `Search/SearchController::projection` — 9 fields
`id, entity, name, filters, columns, sort, is_shared, is_mine, note`

### `ServiceCatalog/ProgramController::citizenProjection` — 11 fields
`id, code, name, description, owner_office, target_population, benefit_type, accepts_applications, applications_close_at, decided_by, turnaround_target_days`

### `ServiceCatalog/ProgramController::staffProjection` — 7 fields
`status, is_citizen_visible, authority, funding_source_label, active_from, active_to, eligibility_guidance_version`

### `ServiceCatalog/ProgramController::requirementProjection` — 9 fields
`id, code, label, obligation, condition_note, citizen_instructions, template_version, display_order, accepted_documents`

### `ServiceCatalog/ProgramController::criterionProjection` — 9 fields
`id, code, fact, comparator, value, value_max, citizen_explanation, is_blocking, guidance_version`

### `ServiceCatalog/ProviderController::projection` — 13 fields
`id, name, destination_type, status, services_offered, channels, address, contact, usual_response_days, notes, verified_at, problems, is_accepting_referrals`

### `Tasks/TaskController::projection` — 14 fields
`id, type, title, subject_type, subject_id, assigned_to, team, priority, status, due_on, is_overdue, outcome, raised_by_event, completed_at`

### `Welfare/AssessmentController::intakeProjection` — 8 fields
`id, case_id, case_number, status, source, category, urgency, submitted_at`

### `Welfare/AssessmentController::assessmentProjection` — 11 fields
`id, template_code, template_version, status, recommendation, recommendation_reason, findings, assessor_subject_id, completed_at, suggested_next_status, answers`

### `Welfare/CaseController::listProjection` — 11 fields
`id, case_number, type, status, priority, resident_id, barangay_id, assigned_to, opened_at, last_activity_at, next_follow_up_on`

### `Welfare/CaseController::detailProjection` — 10 fields
`household_id, program_id, priority_reason, needs_home_visit, is_escalated, opened_by, closed_at, archived_at, is_open, available_transitions`

### `Welfare/CaseEligibilityController::checkProjection` — 9 fields
`id, program_id, program_code, guidance_version, outcome, is_advisory, evaluated_by, evaluated_at, results`

### `Welfare/CaseRequirementController::projection` — 11 fields
`id, code, label, obligation, template_version, citizen_instructions, applicability, applicability_reason, is_satisfied, is_outstanding, current_version`

### `Welfare/CaseRequirementController::versionProjection` — 14 fields
`id, version, source, document_number, issued_on, expires_on, validity, verification_status, verification_note, verified_at, received_at, superseded_at, superseded_reason, file`

### `Welfare/CaseRequirementController::requestProjection` — 10 fields
`id, requirement_id, state, channel, message, needed_by, requested_at, closed_at, withdrawn_reason, is_applicant_overdue`

### `Welfare/EnrollmentController::projection` — 14 fields
`id, program_id, program_code, resident_id, household_id, source_case_id, status, effective_from, effective_to, entry_reason, exit_reason, note, enrolled_by, exited_by`

### `Welfare/FieldVisitController::listProjection` — 11 fields
`id, reference_number, resident_id, status, purpose, assigned_to, scheduled_for, scheduled_window, address_visited, completed_at, is_overdue`

### `Welfare/FieldVisitController::observationProjection` — 6 fields
`id, kind, kind_label, body, attributed_to, recorded_at`

### `Welfare/MyAssistanceController::draftProjection` — 10 fields
`id, source, category, urgency, narrative, requested_service_id, consent_reference, expires_at, submitted_at, is_editable`

### `Welfare/MyAssistanceController::intakeProjection` — 5 fields
`id, reference, status, status_message, submitted_at`

### `Welfare/MyAssistanceController::submittedProjection` — 5 fields
`id, reference, status, status_message, submitted_at`

### `Welfare/MyCaseController::summaryProjection` — 6 fields
`id, reference, status, status_message, submitted_at, last_update_at`

### `Welfare/MyCaseController::detailProjection` — 3 fields
`is_open, available_actions, timeline`

### `Welfare/MyReferralController::projection` — 5 fields
`reference, referred_to, status, status_message, referred_on`

### `Welfare/MyRequirementController::projection` — 8 fields
`id, label, instructions, is_required, is_provided, is_accepted, status_message, current_version`

### `Welfare/MyRequirementController::versionProjection` — 5 fields
`id, submitted_at, file_name, byte_size, message`

### `Welfare/ReferralController::projection` — 18 fields
`id, reference_number, resident_id, destination_type, destination_name, destination_contact, provider_id, status, urgency, service_requested, reason, referred_at, sent_at, follow_up_on, responded_at, outcome, is_overdue, available_transitions`

### `Welfare/ReleaseController::projection` — 22 fields
`id, reference_number, resident_id, program_id, program_code, approval_reference, sequence, kind, amount_centavos, currency, in_kind_description, release_mode, funding_source, scheduled_for, release_location, status, released_at, acknowledged_by_name, acknowledged_relationship, acknowledgement_method, outcome_reason, available_transitions`

### `Welfare/ReleaseController::batchProjection` — 6 fields
`id, reference_number, name, scheduled_for, location, status`

### `Welfare/SafeguardingController::projection` — 8 fields
`id, category, status, detail, worker_safety_advisory, raised_at, closed_at, closure_reason`

