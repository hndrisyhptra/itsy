-- Area 3 reference schema; Laravel migrations are the executable installation path.
-- users.id_user is Laravel's preserved numeric user identity; no extra users table.
-- Source audit references/category mapping remain as documented in MYSQL_CONCEPT.md.
-- This SQL reference is not executed by application startup or migrations.

CREATE TABLE users (
    id_user BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMP NULL,
    password VARCHAR(255) NOT NULL,
    remember_token VARCHAR(100) NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
    id_role SMALLINT UNSIGNED PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE areas (
    id_area bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code varchar(10) NOT NULL COMMENT 'Kode Area, mis. 3',
    name varchar(100) NOT NULL COMMENT 'Nama Area, mis. Area 3',
    description varchar(500) DEFAULT NULL,
    is_active tinyint(1) NOT NULL DEFAULT 1,
    created_by bigint(20) UNSIGNED DEFAULT NULL,
    updated_by bigint(20) UNSIGNED DEFAULT NULL,
    created_at timestamp NULL DEFAULT NULL,
    updated_at timestamp NULL DEFAULT NULL,
    deleted_at timestamp NULL DEFAULT NULL,
    UNIQUE KEY areas_code_unique (code),
    KEY areas_created_by_foreign (created_by),
    KEY areas_updated_by_foreign (updated_by),
    KEY areas_is_active_index (is_active)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE regions (
    id_region bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code varchar(255) NOT NULL,
    area_id bigint(20) UNSIGNED DEFAULT NULL,
    name varchar(255) NOT NULL,
    is_active tinyint(1) NOT NULL DEFAULT 1,
    created_by bigint(20) UNSIGNED DEFAULT NULL,
    updated_by bigint(20) UNSIGNED DEFAULT NULL,
    created_at timestamp NULL DEFAULT NULL,
    updated_at timestamp NULL DEFAULT NULL,
    deleted_at timestamp NULL DEFAULT NULL,
    UNIQUE KEY regions_code_unique (code),
    KEY regions_created_by_foreign (created_by),
    KEY regions_updated_by_foreign (updated_by),
    KEY regions_is_active_index (is_active),
    KEY regions_area_id_foreign (area_id),
    CONSTRAINT regions_area_id_foreign FOREIGN KEY (area_id) REFERENCES areas (id_area) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE branches (
    id_branch bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code varchar(255) NOT NULL,
    kode_branch VARCHAR(30) NOT NULL UNIQUE COMMENT 'PP numbering suffix, e.g. R424; code retains PKL etc.',
    name varchar(255) NOT NULL,
    region varchar(50) DEFAULT NULL,
    region_id bigint(20) UNSIGNED DEFAULT NULL,
    is_active tinyint(1) NOT NULL DEFAULT 1,
    created_at timestamp NULL DEFAULT NULL,
    updated_at timestamp NULL DEFAULT NULL,
    created_by bigint(20) UNSIGNED DEFAULT NULL,
    updated_by bigint(20) UNSIGNED DEFAULT NULL,
    deleted_at timestamp NULL DEFAULT NULL,
    UNIQUE KEY branches_code_unique (code),
    KEY branches_region_name_idx (region,name),
    KEY branches_region_id_foreign (region_id),
    KEY branches_created_by_foreign (created_by),
    KEY branches_updated_by_foreign (updated_by),
    KEY branches_is_active_index (is_active),
    CONSTRAINT branches_region_id_foreign FOREIGN KEY (region_id) REFERENCES regions (id_region) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_areas (
    id_service_area bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    workzone varchar(20) NOT NULL,
    name varchar(100) NOT NULL,
    branch_id bigint(20) UNSIGNED NOT NULL,
    region_id bigint(20) UNSIGNED DEFAULT NULL,
    is_active tinyint(1) NOT NULL DEFAULT 1,
    created_by bigint(20) UNSIGNED DEFAULT NULL,
    updated_by bigint(20) UNSIGNED DEFAULT NULL,
    created_at timestamp NULL DEFAULT NULL,
    updated_at timestamp NULL DEFAULT NULL,
    deleted_at timestamp NULL DEFAULT NULL,
    UNIQUE KEY service_areas_workzone_unique (workzone),
    KEY service_areas_region_id_foreign (region_id),
    KEY service_areas_created_by_foreign (created_by),
    KEY service_areas_updated_by_foreign (updated_by),
    KEY service_areas_branch_id_is_active_index (branch_id,is_active),
    KEY service_areas_is_active_index (is_active),
    CONSTRAINT service_areas_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id_branch),
    CONSTRAINT service_areas_region_id_foreign FOREIGN KEY (region_id) REFERENCES regions (id_region) ON DELETE SET NULL,
    UNIQUE (branch_id, id_service_area)
) ENGINE=InnoDB AUTO_INCREMENT=394 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_assignments (
    id_user_assignment BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    role_id SMALLINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (user_id, role_id, branch_id),
    FOREIGN KEY (user_id) REFERENCES users(id_user),
    FOREIGN KEY (role_id) REFERENCES roles(id_role),
    FOREIGN KEY (branch_id) REFERENCES branches(id_branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE work_units (
    id_work_unit SMALLINT UNSIGNED PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE measurement_units (
    id_measurement_unit SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE packages (
    id_package bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code varchar(255) NOT NULL,
    name varchar(255) NOT NULL,
    description text DEFAULT NULL,
    created_by bigint(20) UNSIGNED DEFAULT NULL,
    updated_by bigint(20) UNSIGNED DEFAULT NULL,
    created_at timestamp NULL DEFAULT NULL,
    updated_at timestamp NULL DEFAULT NULL,
    deleted_at timestamp NULL DEFAULT NULL,
    UNIQUE KEY packages_code_unique (code),
    KEY packages_created_by_foreign (created_by),
    KEY packages_updated_by_foreign (updated_by)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE designator_types (
    id_designator_type bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code varchar(255) NOT NULL,
    name varchar(255) NOT NULL,
    description text DEFAULT NULL,
    is_active tinyint(1) NOT NULL DEFAULT 1,
    created_by bigint(20) UNSIGNED DEFAULT NULL,
    updated_by bigint(20) UNSIGNED DEFAULT NULL,
    created_at timestamp NULL DEFAULT NULL,
    updated_at timestamp NULL DEFAULT NULL,
    deleted_at timestamp NULL DEFAULT NULL,
    UNIQUE KEY designator_types_code_unique (code),
    KEY designator_types_created_by_foreign (created_by),
    KEY designator_types_updated_by_foreign (updated_by),
    KEY designator_types_is_active_index (is_active)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE designators (
    id_designator bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code varchar(255) NOT NULL,
    item_name text NOT NULL,
    unit varchar(255) NOT NULL,
    designator_type_id bigint(20) UNSIGNED DEFAULT NULL,
    designator_category_id bigint(20) UNSIGNED DEFAULT NULL,
    created_by bigint(20) UNSIGNED DEFAULT NULL,
    updated_by bigint(20) UNSIGNED DEFAULT NULL,
    created_at timestamp NULL DEFAULT NULL,
    updated_at timestamp NULL DEFAULT NULL,
    deleted_at timestamp NULL DEFAULT NULL,
    UNIQUE KEY designators_code_unique (code),
    KEY designators_created_by_foreign (created_by),
    KEY designators_updated_by_foreign (updated_by),
    KEY designators_designator_type_id_foreign (designator_type_id),
    KEY designators_designator_category_id_foreign (designator_category_id),
    CONSTRAINT designators_designator_type_id_foreign FOREIGN KEY (designator_type_id) REFERENCES designator_types (id_designator_type) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1760 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE designator_package_prices (
    id_designator_package_price bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    designator_id bigint(20) UNSIGNED NOT NULL,
    package_id bigint(20) UNSIGNED NOT NULL,
    price decimal(15,2) NOT NULL,
    created_by bigint(20) UNSIGNED DEFAULT NULL,
    updated_by bigint(20) UNSIGNED DEFAULT NULL,
    created_at timestamp NULL DEFAULT NULL,
    updated_at timestamp NULL DEFAULT NULL,
    deleted_at timestamp NULL DEFAULT NULL,
    UNIQUE KEY designator_package_prices_designator_id_package_id_unique (designator_id,package_id),
    KEY designator_package_prices_package_id_foreign (package_id),
    KEY designator_package_prices_created_by_foreign (created_by),
    KEY designator_package_prices_updated_by_foreign (updated_by),
    CONSTRAINT designator_package_prices_designator_id_foreign FOREIGN KEY (designator_id) REFERENCES designators (id_designator) ON DELETE CASCADE,
    CONSTRAINT designator_package_prices_package_id_foreign FOREIGN KEY (package_id) REFERENCES packages (id_package) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1758 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE khs_price_lists (
    id_khs_price_list BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    reference_no VARCHAR(150) NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    valid_from DATE NOT NULL,
    valid_until DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
    UNIQUE (reference_no, version_no),
    FOREIGN KEY (package_id) REFERENCES packages(id_package),
    FOREIGN KEY (branch_id) REFERENCES branches(id_branch),
    CONSTRAINT khs_period CHECK (valid_until IS NULL OR valid_until >= valid_from),
    CONSTRAINT khs_status CHECK (status IN ('DRAFT','ACTIVE','RETIRED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE khs_prices (
    id_khs_price BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    price_list_id BIGINT UNSIGNED NOT NULL,
    designator_id BIGINT UNSIGNED NOT NULL,
    source_price_id BIGINT UNSIGNED NULL,
    material_price DECIMAL(20,2) NULL,
    service_price DECIMAL(20,2) NULL,
    UNIQUE (price_list_id, designator_id),
    FOREIGN KEY (price_list_id) REFERENCES khs_price_lists(id_khs_price_list),
    FOREIGN KEY (designator_id) REFERENCES designators(id_designator),
    FOREIGN KEY (source_price_id) REFERENCES designator_package_prices(id_designator_package_price),
    CONSTRAINT khs_components CHECK (material_price IS NOT NULL OR service_price IS NOT NULL),
    CONSTRAINT khs_material_nonnegative CHECK (material_price IS NULL OR material_price >= 0),
    CONSTRAINT khs_service_nonnegative CHECK (service_price IS NULL OR service_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE projects (
    id_project CHAR(36) PRIMARY KEY,
    project_code VARCHAR(100) NOT NULL UNIQUE,
    branch_id BIGINT UNSIGNED NOT NULL,
    service_area_id BIGINT UNSIGNED NULL,
    work_unit_id SMALLINT UNSIGNED NOT NULL,
    name VARCHAR(250) NOT NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id_branch),
    FOREIGN KEY (branch_id, service_area_id) REFERENCES service_areas(branch_id, id_service_area),
    FOREIGN KEY (work_unit_id) REFERENCES work_units(id_work_unit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE numbering_rules (
    id_numbering_rule BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_type VARCHAR(30) NOT NULL UNIQUE,
    pattern VARCHAR(100) NOT NULL DEFAULT '{SEQ}/LG.000/TA-03{BRANCH}/{MM}-{YYYY}',
    sequence_padding TINYINT UNSIGNED NOT NULL DEFAULT 4,
    timezone_name VARCHAR(60) NOT NULL DEFAULT 'Asia/Jakarta',
    sequence_scope VARCHAR(20) NOT NULL DEFAULT 'GLOBAL',
    updated_by BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id_user),
    CONSTRAINT numbering_padding CHECK (sequence_padding BETWEEN 1 AND 12),
    CONSTRAINT numbering_scope CHECK (sequence_scope IN ('GLOBAL','YEAR','MONTH','BRANCH_YEAR','BRANCH_MONTH'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE number_sequences (
    id_number_sequence BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id BIGINT UNSIGNED NOT NULL,
    scope_key VARCHAR(100) NOT NULL,
    next_value BIGINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE (rule_id, scope_key),
    FOREIGN KEY (rule_id) REFERENCES numbering_rules(id_numbering_rule),
    CONSTRAINT sequence_positive CHECK (next_value > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE project_plans (
    id_pp CHAR(36) PRIMARY KEY,
    project_id CHAR(36) NOT NULL,
    pp_no VARCHAR(150) NOT NULL UNIQUE,
    numbering_rule_id BIGINT UNSIGNED NOT NULL,
    number_scope_key VARCHAR(100) NOT NULL,
    number_sequence BIGINT UNSIGNED NOT NULL,
    number_pattern_snapshot VARCHAR(100) NOT NULL,
    kode_branch_snapshot VARCHAR(30) NOT NULL,
    number_period CHAR(7) NOT NULL COMMENT 'MM-YYYY in server Asia/Jakarta time',
    numbered_at DATETIME(6) NOT NULL,
    root_pp_id CHAR(36) NULL,
    parent_pp_id CHAR(36) NULL,
    usage_date DATE NOT NULL,
    budget DECIMAL(20,2) NOT NULL,
    pm_user_id BIGINT UNSIGNED NOT NULL,
    manager_user_id BIGINT UNSIGNED NOT NULL,
    introduction TEXT NOT NULL,
    risk_plan TEXT NOT NULL,
    communication_plan TEXT NOT NULL,
    quality_plan TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
    revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    workflow_version SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id_project),
    FOREIGN KEY (numbering_rule_id, number_scope_key) REFERENCES number_sequences(rule_id, scope_key),
    FOREIGN KEY (root_pp_id) REFERENCES project_plans(id_pp),
    FOREIGN KEY (parent_pp_id) REFERENCES project_plans(id_pp),
    FOREIGN KEY (pm_user_id) REFERENCES users(id_user),
    FOREIGN KEY (manager_user_id) REFERENCES users(id_user),
    FOREIGN KEY (created_by) REFERENCES users(id_user),
    INDEX pp_inbox_pm (pm_user_id, status),
    INDEX pp_inbox_manager (manager_user_id, status),
    UNIQUE (numbering_rule_id, number_scope_key, number_sequence),
    CONSTRAINT pp_number_positive CHECK (number_sequence > 0),
    CONSTRAINT pp_budget_positive CHECK (budget > 0),
    CONSTRAINT pp_status CHECK (status IN ('DRAFT','SUBMITTED','RETURNED','REJECTED','APPROVED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE project_plan_team (
    id_pp_team BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pp_id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    name_snapshot VARCHAR(150) NOT NULL,
    job_snapshot VARCHAR(150) NOT NULL,
    FOREIGN KEY (pp_id) REFERENCES project_plans(id_pp),
    FOREIGN KEY (user_id) REFERENCES users(id_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE project_plan_schedule (
    id_pp_schedule BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pp_id CHAR(36) NOT NULL,
    stage_no TINYINT UNSIGNED NOT NULL,
    stage_name VARCHAR(100) NOT NULL,
    work_days DECIMAL(8,2) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    UNIQUE (pp_id, stage_no),
    FOREIGN KEY (pp_id) REFERENCES project_plans(id_pp),
    CONSTRAINT pp_schedule_valid CHECK (stage_no BETWEEN 1 AND 5 AND work_days >= 0 AND end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE project_plan_costs (
    id_pp_cost BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pp_id CHAR(36) NOT NULL,
    cost_type VARCHAR(20) NOT NULL,
    amount DECIMAL(20,2) NOT NULL,
    UNIQUE (pp_id, cost_type),
    FOREIGN KEY (pp_id) REFERENCES project_plans(id_pp),
    CONSTRAINT pp_cost_type CHECK (cost_type IN ('MANDOR','NONSTOCK','BOP','PERMIT')),
    CONSTRAINT pp_cost_nonnegative CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE boq_lines (
    id_boq CHAR(36) PRIMARY KEY,
    root_pp_id CHAR(36) NOT NULL,
    khs_price_id BIGINT UNSIGNED NOT NULL,
    designator_code_snapshot VARCHAR(255) NOT NULL,
    description_snapshot TEXT NOT NULL,
    unit_snapshot VARCHAR(255) NOT NULL,
    UNIQUE (root_pp_id, khs_price_id),
    FOREIGN KEY (root_pp_id) REFERENCES project_plans(id_pp),
    FOREIGN KEY (khs_price_id) REFERENCES khs_prices(id_khs_price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE boq_components (
    id_boq_component CHAR(36) PRIMARY KEY,
    boq_line_id CHAR(36) NOT NULL,
    component VARCHAR(1) NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    official_unit_price DECIMAL(20,2) NOT NULL,
    UNIQUE (boq_line_id, component),
    FOREIGN KEY (boq_line_id) REFERENCES boq_lines(id_boq),
    CONSTRAINT boq_component_type CHECK (component IN ('M','S')),
    CONSTRAINT boq_component_numbers CHECK (quantity > 0 AND quantity <= 1000000000 AND official_unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE contracts (
    id_contract CHAR(36) PRIMARY KEY,
    pp_id CHAR(36) NOT NULL,
    mandor_name VARCHAR(150) NOT NULL,
    mandor_address TEXT NOT NULL,
    bakh_no VARCHAR(150) NULL UNIQUE,
    spk_no VARCHAR(150) NULL UNIQUE,
    stage VARCHAR(30) NOT NULL DEFAULT 'NEGOTIATION',
    start_date DATE NULL,
    end_date DATE NULL,
    supervisor VARCHAR(150) NOT NULL,
    supervisor_phone VARCHAR(40) NOT NULL,
    signer_name VARCHAR(150) NOT NULL,
    signer_position VARCHAR(150) NOT NULL,
    issued_at DATETIME(6) NULL,
    FOREIGN KEY (pp_id) REFERENCES project_plans(id_pp),
    CONSTRAINT contract_period CHECK (end_date >= start_date),
    CONSTRAINT contract_stage CHECK (stage IN ('NEGOTIATION','BAKH_ISSUED','BAKH_ATTESTED','SPK_DRAFT','SPK_ISSUED','SPK_ATTESTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE contract_components (
    id_contract_component CHAR(36) PRIMARY KEY,
    contract_id CHAR(36) NOT NULL,
    boq_component_id CHAR(36) NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    khs_ceiling_snapshot DECIMAL(20,2) NOT NULL,
    sph_price DECIMAL(20,2) NOT NULL,
    offered_price DECIMAL(20,2) NOT NULL,
    agreed_price DECIMAL(20,2) NOT NULL,
    UNIQUE (contract_id, boq_component_id),
    FOREIGN KEY (contract_id) REFERENCES contracts(id_contract),
    FOREIGN KEY (boq_component_id) REFERENCES boq_components(id_boq_component),
    CONSTRAINT contract_component_numbers CHECK (quantity > 0 AND khs_ceiling_snapshot >= 0 AND sph_price >= 0 AND offered_price >= 0 AND agreed_price >= 0 AND agreed_price <= khs_ceiling_snapshot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE payment_terms (
    id_payment_term CHAR(36) PRIMARY KEY,
    contract_id CHAR(36) NOT NULL,
    term_no TINYINT UNSIGNED NOT NULL,
    term_type VARCHAR(10) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
    pm_user_id BIGINT UNSIGNED NOT NULL,
    manager_user_id BIGINT UNSIGNED NOT NULL,
    approved_amount DECIMAL(20,2) NULL,
    submitted_at DATETIME(6) NULL,
    approved_at DATETIME(6) NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    workflow_version SMALLINT UNSIGNED NOT NULL DEFAULT 2,
    UNIQUE (contract_id, term_no),
    FOREIGN KEY (contract_id) REFERENCES contracts(id_contract),
    FOREIGN KEY (pm_user_id) REFERENCES users(id_user),
    FOREIGN KEY (manager_user_id) REFERENCES users(id_user),
    INDEX payment_inbox_pm (pm_user_id, status),
    INDEX payment_inbox_manager (manager_user_id, status),
    CONSTRAINT term_sequence CHECK (term_no BETWEEN 1 AND 3),
    CONSTRAINT term_kind CHECK (term_type IN ('PARTIAL','FINAL') AND (term_no < 3 OR term_type = 'FINAL')),
    CONSTRAINT term_status CHECK (status IN ('DRAFT','SUBMITTED','RETURNED','REJECTED','APPROVED')),
    CONSTRAINT term_amount CHECK (approved_amount IS NULL OR approved_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE term_actuals (
    id_term_actual BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    term_id CHAR(36) NOT NULL,
    contract_component_id CHAR(36) NOT NULL,
    actual_quantity DECIMAL(18,3) NOT NULL,
    UNIQUE (term_id, contract_component_id),
    FOREIGN KEY (term_id) REFERENCES payment_terms(id_payment_term),
    FOREIGN KEY (contract_component_id) REFERENCES contract_components(id_contract_component),
    CONSTRAINT actual_nonnegative CHECK (actual_quantity >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE approval_runs (
    id_approval_run CHAR(36) PRIMARY KEY,
    pp_id CHAR(36) NULL,
    term_id CHAR(36) NULL,
    cycle_no INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    submitted_by BIGINT UNSIGNED NOT NULL,
    submitted_at DATETIME(6) NOT NULL,
    UNIQUE (pp_id, cycle_no),
    UNIQUE (term_id, cycle_no),
    FOREIGN KEY (pp_id) REFERENCES project_plans(id_pp),
    FOREIGN KEY (term_id) REFERENCES payment_terms(id_payment_term),
    FOREIGN KEY (submitted_by) REFERENCES users(id_user),
    CONSTRAINT approval_one_subject CHECK ((pp_id IS NOT NULL AND term_id IS NULL) OR (pp_id IS NULL AND term_id IS NOT NULL)),
    CONSTRAINT approval_run_status CHECK (status IN ('PENDING','APPROVED','RETURNED','REJECTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE approval_steps (
    id_approval_step CHAR(36) PRIMARY KEY,
    run_id CHAR(36) NOT NULL,
    step_no TINYINT UNSIGNED NOT NULL,
    expected_role VARCHAR(20) NOT NULL,
    assigned_user_id BIGINT UNSIGNED NOT NULL,
    assigned_name_snapshot VARCHAR(150) NOT NULL,
    UNIQUE (run_id, step_no),
    FOREIGN KEY (run_id) REFERENCES approval_runs(id_approval_run),
    FOREIGN KEY (assigned_user_id) REFERENCES users(id_user),
    INDEX assigned_inbox (assigned_user_id, run_id),
    CONSTRAINT two_approval_steps CHECK ((step_no = 1 AND expected_role = 'PM_KONSTRUKSI') OR (step_no = 2 AND expected_role = 'MANAGER_KONSTRUKSI'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE approval_decisions (
    id_approval_decision CHAR(36) PRIMARY KEY,
    step_id CHAR(36) NOT NULL UNIQUE,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    actor_name_snapshot VARCHAR(150) NOT NULL,
    decision VARCHAR(15) NOT NULL,
    note TEXT NOT NULL,
    decided_at DATETIME(6) NOT NULL,
    FOREIGN KEY (step_id) REFERENCES approval_steps(id_approval_step),
    FOREIGN KEY (actor_user_id) REFERENCES users(id_user),
    CONSTRAINT decision_type CHECK (decision IN ('APPROVE','RETURN','REJECT')),
    CONSTRAINT decision_reason CHECK (decision = 'APPROVE' OR CHAR_LENGTH(TRIM(note)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE document_files (
    id_document_file CHAR(36) PRIMARY KEY,
    storage_disk VARCHAR(40) NOT NULL,
    storage_key VARCHAR(500) NOT NULL UNIQUE,
    original_name VARCHAR(200) NOT NULL,
    mime_type VARCHAR(80) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    uploaded_at DATETIME(6) NOT NULL,
    FOREIGN KEY (uploaded_by) REFERENCES users(id_user),
    CONSTRAINT file_bytes CHECK (byte_size > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE boq_import_templates (
    id_boq_import_template BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    file_id CHAR(36) NOT NULL,
    worksheet_name VARCHAR(100) NOT NULL,
    header_row INT UNSIGNED NOT NULL,
    column_mapping JSON NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE (code, version_no),
    FOREIGN KEY (file_id) REFERENCES document_files(id_document_file),
    FOREIGN KEY (created_by) REFERENCES users(id_user),
    CONSTRAINT boq_template_version CHECK (version_no > 0 AND header_row > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE boq_import_batches (
    id_boq_import CHAR(36) PRIMARY KEY,
    draft_id CHAR(36) NOT NULL,
    pp_id CHAR(36) NULL,
    template_id BIGINT UNSIGNED NOT NULL,
    file_id CHAR(36) NOT NULL UNIQUE,
    khs_price_list_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'UPLOADED',
    uploaded_by BIGINT UNSIGNED NOT NULL,
    uploaded_at DATETIME(6) NOT NULL,
    validated_by BIGINT UNSIGNED NULL,
    validated_at DATETIME(6) NULL,
    applied_at DATETIME(6) NULL,
    FOREIGN KEY (pp_id) REFERENCES project_plans(id_pp),
    FOREIGN KEY (template_id) REFERENCES boq_import_templates(id_boq_import_template),
    FOREIGN KEY (file_id) REFERENCES document_files(id_document_file),
    FOREIGN KEY (khs_price_list_id) REFERENCES khs_price_lists(id_khs_price_list),
    FOREIGN KEY (uploaded_by) REFERENCES users(id_user),
    FOREIGN KEY (validated_by) REFERENCES users(id_user),
    INDEX boq_draft_owner (uploaded_by, draft_id, status),
    INDEX boq_pp_history (pp_id, uploaded_at),
    CONSTRAINT boq_import_status CHECK (status IN ('UPLOADED','PARSED','REVIEW','VALIDATED','APPLIED','REJECTED','SUPERSEDED')),
    CONSTRAINT boq_import_validation CHECK (status NOT IN ('VALIDATED','APPLIED') OR (validated_by IS NOT NULL AND validated_at IS NOT NULL)),
    CONSTRAINT boq_import_applied CHECK (status <> 'APPLIED' OR (pp_id IS NOT NULL AND applied_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE boq_import_rows (
    id_boq_import_row BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id CHAR(36) NOT NULL,
    row_no INT UNSIGNED NOT NULL,
    raw_values JSON NOT NULL,
    designator_code VARCHAR(255) NULL,
    unit_code VARCHAR(255) NULL,
    quantity DECIMAL(18,3) NULL,
    excel_material_price DECIMAL(20,2) NULL,
    excel_service_price DECIMAL(20,2) NULL,
    matched_khs_price_id BIGINT UNSIGNED NULL,
    price_mismatch BOOLEAN NOT NULL DEFAULT FALSE,
    status VARCHAR(20) NOT NULL DEFAULT 'UNMATCHED',
    validation_errors JSON NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME(6) NULL,
    review_note TEXT NULL,
    UNIQUE (batch_id, row_no),
    FOREIGN KEY (batch_id) REFERENCES boq_import_batches(id_boq_import),
    FOREIGN KEY (matched_khs_price_id) REFERENCES khs_prices(id_khs_price),
    FOREIGN KEY (reviewed_by) REFERENCES users(id_user),
    CONSTRAINT boq_import_row_positive CHECK (row_no > 0),
    CONSTRAINT boq_import_row_status CHECK (status IN ('UNMATCHED','MATCHED','REVIEW','INVALID','VALIDATED')),
    CONSTRAINT boq_import_row_valid CHECK (status <> 'VALIDATED' OR (matched_khs_price_id IS NOT NULL AND quantity IS NOT NULL AND quantity > 0 AND quantity <= 1000000000 AND validation_errors IS NULL)),
    CONSTRAINT boq_import_row_review CHECK (status <> 'VALIDATED' OR price_mismatch = FALSE OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND review_note IS NOT NULL AND CHAR_LENGTH(TRIM(review_note)) > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE boq_lines
    ADD source_import_id CHAR(36) NULL,
    ADD source_row_no INT UNSIGNED NULL,
    ADD UNIQUE (source_import_id, source_row_no),
    ADD CONSTRAINT boq_line_source_pair CHECK ((source_import_id IS NULL AND source_row_no IS NULL) OR (source_import_id IS NOT NULL AND source_row_no IS NOT NULL)),
    ADD FOREIGN KEY (source_import_id, source_row_no) REFERENCES boq_import_rows(batch_id, row_no);

CREATE TABLE contract_documents (
    id_contract_document CHAR(36) PRIMARY KEY,
    contract_id CHAR(36) NOT NULL,
    document_type VARCHAR(10) NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    file_id CHAR(36) NULL,
    evidence_reference VARCHAR(500) NULL,
    attested_by BIGINT UNSIGNED NULL,
    attested_at DATETIME(6) NULL,
    UNIQUE (contract_id, document_type, version_no),
    FOREIGN KEY (contract_id) REFERENCES contracts(id_contract),
    FOREIGN KEY (file_id) REFERENCES document_files(id_document_file),
    FOREIGN KEY (attested_by) REFERENCES users(id_user),
    CONSTRAINT contract_document_kind CHECK (document_type IN ('BAKH','SPK'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE term_scans (
    id_term_scan CHAR(36) PRIMARY KEY,
    term_id CHAR(36) NOT NULL,
    file_id CHAR(36) NOT NULL UNIQUE,
    version_no INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'WAITING',
    verified_by BIGINT UNSIGNED NULL,
    verified_at DATETIME(6) NULL,
    note TEXT NOT NULL,
    UNIQUE (term_id, version_no),
    UNIQUE (term_id, id_term_scan),
    FOREIGN KEY (term_id) REFERENCES payment_terms(id_payment_term),
    FOREIGN KEY (file_id) REFERENCES document_files(id_document_file),
    FOREIGN KEY (verified_by) REFERENCES users(id_user),
    INDEX finance_scan_queue (status, term_id),
    CONSTRAINT scan_status CHECK (status IN ('WAITING','VALID','CORRECTION')),
    CONSTRAINT scan_verifier CHECK (status = 'WAITING' OR (verified_by IS NOT NULL AND verified_at IS NOT NULL)),
    CONSTRAINT scan_correction_reason CHECK (status <> 'CORRECTION' OR CHAR_LENGTH(TRIM(note)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE apm_settlements (
    id_apm_settlement CHAR(36) PRIMARY KEY,
    term_id CHAR(36) NOT NULL UNIQUE,
    verified_scan_id CHAR(36) NOT NULL,
    apm_no VARCHAR(60) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
    amount DECIMAL(20,2) NOT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    FOREIGN KEY (term_id, verified_scan_id) REFERENCES term_scans(term_id, id_term_scan),
    FOREIGN KEY (recorded_by) REFERENCES users(id_user),
    CONSTRAINT settlement_positive CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ba_completions (
    id_ba_pp CHAR(36) PRIMARY KEY,
    final_term_id CHAR(36) NOT NULL UNIQUE,
    document_no VARCHAR(150) NOT NULL UNIQUE,
    status VARCHAR(15) NOT NULL DEFAULT 'DRAFT',
    evidence_reference VARCHAR(500) NULL,
    file_id CHAR(36) NULL,
    attested_by BIGINT UNSIGNED NULL,
    attested_at DATETIME(6) NULL,
    FOREIGN KEY (final_term_id) REFERENCES payment_terms(id_payment_term),
    FOREIGN KEY (file_id) REFERENCES document_files(id_document_file),
    FOREIGN KEY (attested_by) REFERENCES users(id_user),
    CONSTRAINT completion_status CHECK (status IN ('DRAFT','ATTESTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE volume_releases (
    id_volume_release CHAR(36) PRIMARY KEY,
    completion_id CHAR(36) NOT NULL,
    contract_component_id CHAR(36) NOT NULL,
    boq_component_id CHAR(36) NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    authorized_by BIGINT UNSIGNED NOT NULL,
    authorized_at DATETIME(6) NOT NULL,
    UNIQUE (id_volume_release, boq_component_id),
    FOREIGN KEY (completion_id) REFERENCES ba_completions(id_ba_pp),
    FOREIGN KEY (contract_component_id) REFERENCES contract_components(id_contract_component),
    FOREIGN KEY (boq_component_id) REFERENCES boq_components(id_boq_component),
    FOREIGN KEY (authorized_by) REFERENCES users(id_user),
    CONSTRAINT release_positive CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE continuation_allocations (
    id_continuation_allocation BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    continuation_pp_id CHAR(36) NOT NULL,
    source_release_id CHAR(36) NOT NULL,
    boq_component_id CHAR(36) NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    UNIQUE (continuation_pp_id, source_release_id, boq_component_id),
    FOREIGN KEY (continuation_pp_id) REFERENCES project_plans(id_pp),
    FOREIGN KEY (source_release_id, boq_component_id) REFERENCES volume_releases(id_volume_release, boq_component_id),
    CONSTRAINT allocation_positive CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE command_receipts (
    id_command_receipt CHAR(36) PRIMARY KEY,
    request_uuid CHAR(36) NOT NULL UNIQUE,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    command_name VARCHAR(80) NOT NULL,
    aggregate_type VARCHAR(40) NOT NULL,
    aggregate_id CHAR(36) NOT NULL,
    expected_revision BIGINT UNSIGNED NOT NULL,
    resulting_revision BIGINT UNSIGNED NOT NULL,
    before_sha256 CHAR(64) NOT NULL,
    after_sha256 CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (actor_user_id) REFERENCES users(id_user),
    INDEX aggregate_audit (aggregate_type, aggregate_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE volume_balances (
    id_volume_balance BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    boq_component_id CHAR(36) NOT NULL UNIQUE,
    original_quantity DECIMAL(18,3) NOT NULL,
    available DECIMAL(18,3) NOT NULL,
    reserved DECIMAL(18,3) NOT NULL,
    committed DECIMAL(18,3) NOT NULL,
    accepted DECIMAL(18,3) NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (boq_component_id) REFERENCES boq_components(id_boq_component),
    CONSTRAINT balance_nonnegative CHECK (original_quantity >= 0 AND available >= 0 AND reserved >= 0 AND committed >= 0 AND accepted >= 0),
    CONSTRAINT volume_conservation CHECK (original_quantity = available + reserved + committed + accepted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE volume_movements (
    id_volume_movement CHAR(36) PRIMARY KEY,
    receipt_id CHAR(36) NOT NULL,
    boq_component_id CHAR(36) NOT NULL,
    movement_type VARCHAR(30) NOT NULL,
    source_type VARCHAR(40) NOT NULL,
    source_id CHAR(36) NOT NULL,
    available_delta DECIMAL(18,3) NOT NULL,
    reserved_delta DECIMAL(18,3) NOT NULL,
    committed_delta DECIMAL(18,3) NOT NULL,
    accepted_delta DECIMAL(18,3) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE (receipt_id, boq_component_id),
    FOREIGN KEY (receipt_id) REFERENCES command_receipts(id_command_receipt),
    FOREIGN KEY (boq_component_id) REFERENCES boq_components(id_boq_component),
    CONSTRAINT movement_balanced CHECK (available_delta + reserved_delta + committed_delta + accepted_delta = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE master_import_batches (
    id_master_import CHAR(36) PRIMARY KEY,
    file_id CHAR(36) NOT NULL,
    master_type VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'STAGED',
    imported_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (file_id) REFERENCES document_files(id_document_file),
    FOREIGN KEY (imported_by) REFERENCES users(id_user),
    CONSTRAINT import_master_type CHECK (master_type IN ('AREA','REGION','BRANCH','SERVICE_AREA','DESIGNATOR_TYPE','DESIGNATOR','PACKAGE','KHS')),
    CONSTRAINT import_status CHECK (status IN ('STAGED','VALIDATED','APPLIED','REJECTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE master_import_rows (
    id_master_import_row BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id CHAR(36) NOT NULL,
    row_no INT UNSIGNED NOT NULL,
    raw_values JSON NOT NULL,
    validation_errors JSON NULL,
    UNIQUE (batch_id, row_no),
    FOREIGN KEY (batch_id) REFERENCES master_import_batches(id_master_import)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
