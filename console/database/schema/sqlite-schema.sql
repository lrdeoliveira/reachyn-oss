CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_expiration_index" on "cache"("expiration");
CREATE TABLE IF NOT EXISTS "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE INDEX "cache_locks_expiration_index" on "cache_locks"("expiration");
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" varchar not null,
  "queue" varchar not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE INDEX "failed_jobs_connection_queue_failed_at_index" on "failed_jobs"(
  "connection",
  "queue",
  "failed_at"
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "tenants"(
  "id" integer primary key autoincrement not null,
  "slug" varchar not null,
  "name" varchar not null,
  "plan" varchar not null default 'starter',
  "zernio_profile_id" varchar,
  "billing_status" varchar not null default 'none',
  "voice_id" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "stripe_id" varchar,
  "pm_type" varchar,
  "pm_last_four" varchar,
  "trial_ends_at" datetime,
  "search_keys" text
);
CREATE UNIQUE INDEX "tenants_slug_unique" on "tenants"("slug");
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "tenant_id" integer,
  "role" varchar not null default 'client',
  foreign key("tenant_id") references "tenants"("id") on delete set null
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE TABLE IF NOT EXISTS "connections"(
  "id" integer primary key autoincrement not null,
  "tenant_id" integer not null,
  "platform" varchar not null,
  "label" varchar not null default '',
  "secret" text not null,
  "status" varchar not null default 'unchecked',
  "detail" varchar not null default '',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade
);
CREATE UNIQUE INDEX "connections_tenant_id_platform_label_unique" on "connections"(
  "tenant_id",
  "platform",
  "label"
);
CREATE TABLE IF NOT EXISTS "drafts"(
  "id" integer primary key autoincrement not null,
  "tenant_id" integer not null,
  "keyword" varchar not null default '',
  "research" text,
  "texts" text,
  "media" text,
  "image_url" text,
  "video_url" text,
  "image_prompt" text,
  "status" varchar not null default 'rascunho',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "approvals"(
  "id" integer primary key autoincrement not null,
  "tenant_id" integer not null,
  "job_id" varchar,
  "keyword" varchar not null default '',
  "preview_text" text,
  "image_url" text,
  "video_url" text,
  "resume_url" text,
  "cancel_url" text,
  "meta" text,
  "status" varchar not null default 'pendente',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("tenant_id") references "tenants"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "usages"(
  "id" integer primary key autoincrement not null,
  "tenant_id" integer not null,
  "period" varchar not null,
  "kind" varchar not null,
  "count" integer not null default '0',
  foreign key("tenant_id") references "tenants"("id") on delete cascade
);
CREATE UNIQUE INDEX "usages_tenant_id_period_kind_unique" on "usages"(
  "tenant_id",
  "period",
  "kind"
);
CREATE INDEX "tenants_stripe_id_index" on "tenants"("stripe_id");
CREATE TABLE IF NOT EXISTS "subscriptions"(
  "id" integer primary key autoincrement not null,
  "tenant_id" integer not null,
  "type" varchar not null,
  "stripe_id" varchar not null,
  "stripe_status" varchar not null,
  "stripe_price" varchar,
  "quantity" integer,
  "trial_ends_at" datetime,
  "ends_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "subscriptions_tenant_id_stripe_status_index" on "subscriptions"(
  "tenant_id",
  "stripe_status"
);
CREATE UNIQUE INDEX "subscriptions_stripe_id_unique" on "subscriptions"(
  "stripe_id"
);
CREATE TABLE IF NOT EXISTS "subscription_items"(
  "id" integer primary key autoincrement not null,
  "subscription_id" integer not null,
  "stripe_id" varchar not null,
  "stripe_product" varchar not null,
  "stripe_price" varchar not null,
  "quantity" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "meter_id" varchar,
  "meter_event_name" varchar
);
CREATE INDEX "subscription_items_subscription_id_stripe_price_index" on "subscription_items"(
  "subscription_id",
  "stripe_price"
);
CREATE UNIQUE INDEX "subscription_items_stripe_id_unique" on "subscription_items"(
  "stripe_id"
);
CREATE TABLE IF NOT EXISTS "personal_access_tokens"(
  "id" integer primary key autoincrement not null,
  "tokenable_type" varchar not null,
  "tokenable_id" integer not null,
  "name" text not null,
  "token" varchar not null,
  "abilities" text,
  "last_used_at" datetime,
  "expires_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "personal_access_tokens_tokenable_type_tokenable_id_index" on "personal_access_tokens"(
  "tokenable_type",
  "tokenable_id"
);
CREATE UNIQUE INDEX "personal_access_tokens_token_unique" on "personal_access_tokens"(
  "token"
);
CREATE INDEX "personal_access_tokens_expires_at_index" on "personal_access_tokens"(
  "expires_at"
);
CREATE TABLE IF NOT EXISTS "provider_keys"(
  "id" integer primary key autoincrement not null,
  "provider" varchar not null,
  "api_key" text,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "provider_keys_provider_unique" on "provider_keys"(
  "provider"
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2026_06_08_233000_create_tenants_table',1);
INSERT INTO migrations VALUES(5,'2026_06_08_233001_add_tenant_and_role_to_users_table',1);
INSERT INTO migrations VALUES(6,'2026_06_08_233002_create_connections_table',1);
INSERT INTO migrations VALUES(7,'2026_06_08_233003_create_drafts_table',1);
INSERT INTO migrations VALUES(8,'2026_06_08_233004_create_approvals_table',1);
INSERT INTO migrations VALUES(9,'2026_06_08_233005_create_usages_table',1);
INSERT INTO migrations VALUES(10,'2026_06_08_233009_create_customer_columns',1);
INSERT INTO migrations VALUES(11,'2026_06_08_233010_create_subscriptions_table',1);
INSERT INTO migrations VALUES(12,'2026_06_08_233011_create_subscription_items_table',1);
INSERT INTO migrations VALUES(13,'2026_06_08_233012_add_meter_id_to_subscription_items_table',1);
INSERT INTO migrations VALUES(14,'2026_06_08_233013_add_meter_event_name_to_subscription_items_table',1);
INSERT INTO migrations VALUES(15,'2026_06_09_001453_create_personal_access_tokens_table',2);
INSERT INTO migrations VALUES(16,'2026_06_13_100000_add_search_keys_to_tenants_table',3);
INSERT INTO migrations VALUES(17,'2026_06_15_000000_create_provider_keys_table',3);
