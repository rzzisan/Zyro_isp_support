<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-house billing, phase 0 (plan: /mnt/project-files/billing-system/plan.md, sections 2.2-2.4ক):
 *  - activity_logs: every user's write action, all of the Desk. Append-only (UPDATE/DELETE refused by a trigger) and
 *    hash-chained per company (each row carries the previous row's hash), so a removed row shows.
 *  - the Desk's own ledger: desk_customers, desk_packages, desk_bills, desk_bill_versions, desk_payments,
 *    desk_ledger_entries, desk_customer_balances, desk_billing_runs. Filled read-only from the billing copies by
 *    engine/desk_billing.py; billing_mode stays 'ispdigital', so nothing live changes.
 *  - billing_settings (+ billing_settings_log): the company's billing rules (plan 2.2), owner only.
 * Every table leads with company_id; the big ones are partitioned by month on PostgreSQL (desk_month_partitions()
 * makes the months, the engine adds the coming ones every night). SQLite (tests) gets the same columns unpartitioned.
 */
return new class extends Migration
{
    /** Partitioned tables: name => [partition column, columns, indexes]. id + partition column form the primary key. */
    private function partitioned(): array
    {
        return [
            'activity_logs' => ['created_at', [
                'company_id bigint NOT NULL',
                'user_id bigint NULL',
                "actor varchar(20) NOT NULL DEFAULT 'user'",          // user | bot | system
                "source varchar(20) NOT NULL DEFAULT 'panel'",        // panel | app | api | bot | timer | engine | console
                'action varchar(100) NOT NULL',                       // customer.updated, billing.settings, auth.login ...
                'subject_type varchar(100) NULL',
                'subject_id varchar(100) NULL',
                'customer_code varchar(50) NULL',
                'changes jsonb NULL',                                 // {field: [before, after]}, changed fields only, never secrets
                'note text NULL',
                'ip varchar(64) NULL',
                'device varchar(255) NULL',
                'seq bigint NULL',                                    // per-company order of the hash chain
                'prev_hash varchar(64) NULL',
                'hash varchar(64) NULL',
                'created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP',
            ], [
                ['company_id', 'created_at'],
                ['company_id', 'seq'],
                ['company_id', 'user_id', 'created_at'],
                ['company_id', 'customer_code'],
                ['company_id', 'action', 'created_at'],
            ]],
            'desk_bills' => ['period_start', [
                'company_id bigint NOT NULL',
                'customer_ref bigint NOT NULL',                       // desk_customers.id
                'period_start date NOT NULL',
                'period_end date NOT NULL',
                'amount numeric(12,2) NOT NULL DEFAULT 0',
                'package_ref bigint NULL',
                'package_name varchar(255) NULL',
                "status varchar(16) NOT NULL DEFAULT 'open'",         // open | partial | paid | waived | void
                "source varchar(20) NOT NULL DEFAULT 'desk'",         // desk | ispdigital (imported copy)
                'ext_id bigint NULL',                                 // billing's BillMasterId
                'ext_status varchar(50) NULL',
                'note text NULL',
                'user_id bigint NULL',
                'created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at timestamptz NULL',
            ], [
                ['company_id', 'customer_ref', 'period_start', 'UNIQUE'],
                ['company_id', 'source', 'ext_id', 'period_start', 'UNIQUE'],
                ['company_id', 'period_start', 'status'],
            ]],
            'desk_payments' => ['paid_on', [
                'company_id bigint NOT NULL',
                'customer_ref bigint NOT NULL',
                'paid_on date NOT NULL',                              // Bangladesh date the money came
                'paid_at timestamptz NULL',
                'amount numeric(12,2) NOT NULL DEFAULT 0',
                'discount numeric(12,2) NOT NULL DEFAULT 0',
                'method varchar(50) NULL',
                'gateway varchar(20) NULL',                           // bkash | nagad | ... (online)
                'trx_id varchar(100) NULL',
                'receipt_no varchar(50) NULL',
                'received_by varchar(100) NULL',
                'received_user_id bigint NULL',
                "status varchar(16) NOT NULL DEFAULT 'approved'",     // approved | pending | canceled | transferred
                'is_online boolean NOT NULL DEFAULT false',
                "source varchar(20) NOT NULL DEFAULT 'desk'",
                'ext_id bigint NULL',                                 // billing's BillHeaderId
                'note text NULL',
                'user_id bigint NULL',
                'created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at timestamptz NULL',
            ], [
                ['company_id', 'source', 'ext_id', 'paid_on', 'UNIQUE'],
                ['company_id', 'customer_ref', 'paid_on'],
                ['company_id', 'paid_on', 'method'],
                ['company_id', 'trx_id'],
            ]],
            'desk_ledger_entries' => ['entry_date', [
                'company_id bigint NOT NULL',
                'customer_ref bigint NOT NULL',
                'entry_date date NOT NULL',
                'kind varchar(20) NOT NULL',                          // opening | bill | payment | discount | adjustment | waive | transfer
                'amount numeric(12,2) NOT NULL',                      // + raises the due, - lowers it (advance when the sum is negative)
                'bill_ref bigint NULL',
                'payment_ref bigint NULL',
                "source varchar(20) NOT NULL DEFAULT 'desk'",
                'source_ref varchar(100) NULL',
                'note text NULL',
                'user_id bigint NULL',
                'created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP',
            ], [
                ['company_id', 'customer_ref', 'entry_date'],
                ['company_id', 'source', 'source_ref', 'entry_date', 'UNIQUE'],
                ['company_id', 'kind', 'entry_date'],
            ]],
        ];
    }

    public function up(): void
    {
        $pg = DB::getDriverName() === 'pgsql';
        if ($pg) {
            $this->functions();
        }
        foreach ($this->partitioned() as $table => [$key, $columns, $indexes]) {
            if ($pg) {
                DB::statement("CREATE TABLE {$table} (id bigint GENERATED BY DEFAULT AS IDENTITY, ".implode(', ', $columns)
                    .", PRIMARY KEY (id, {$key})) PARTITION BY RANGE ({$key})");
                // the log from this month, the ledger from 2025 (12 months of history + openings), 15 months ahead;
                // a default partition catches anything outside
                $from = $table === 'activity_logs' ? "date_trunc('month', now())::date" : "DATE '2025-01-01'";
                DB::statement("SELECT desk_month_partitions('{$table}', {$from}, (date_trunc('month', now()) + interval '15 months')::date)");
                DB::statement("CREATE TABLE IF NOT EXISTS {$table}_default PARTITION OF {$table} DEFAULT");
                $time = $key === 'created_at' ? 'created_at' : $key;
                DB::statement("CREATE INDEX {$table}_brin ON {$table} USING brin ({$time})");
            } else {
                DB::statement("CREATE TABLE {$table} (id integer PRIMARY KEY AUTOINCREMENT, ".implode(', ', $columns).')');
            }
            foreach ($indexes as $cols) {
                $unique = end($cols) === 'UNIQUE';
                $cols = array_values(array_diff($cols, ['UNIQUE']));
                $name = $table.'_'.implode('_', $cols).($unique ? '_uq' : '_ix');
                DB::statement(($unique ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ').substr($name, 0, 63)." ON {$table} (".implode(', ', $cols).')');
            }
        }
        if ($pg) {
            $this->triggers();
        }

        Schema::create('desk_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('ext_id')->nullable();                     // billing's package id
            $table->string('name');
            $table->decimal('mbps', 10, 2)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->string('mikrotik_profile')->nullable();
            $table->boolean('active')->default(true);
            $table->string('source', 20)->default('desk');
            $table->timestampsTz();
            $table->unique(['company_id', 'ext_id']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('desk_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('header_id')->nullable();     // billing's CustomerHeaderId (kept to match billing)
            $table->string('customer_code')->nullable();             // "0042", leading zeros kept
            $table->string('username')->nullable();                  // PPPoE ID (no password here)
            $table->string('name')->nullable();
            $table->string('mobile')->nullable();
            $table->unsignedBigInteger('package_ref')->nullable();
            $table->string('package_name')->nullable();
            $table->decimal('monthly_bill', 12, 2)->default(0);
            $table->string('status')->nullable();                    // Active | Inactive | Free | Personal | Left ...
            $table->string('client_type')->nullable();
            $table->boolean('is_vip')->default(false);
            $table->unsignedSmallInteger('expire_day')->nullable();
            $table->date('extended_until')->nullable();
            $table->string('line_state', 16)->default('on');         // on | off | partial | suspended
            $table->unsignedBigInteger('router_id')->nullable();
            $table->string('previous_profile')->nullable();
            $table->string('zone')->nullable();
            $table->date('joined_on')->nullable();
            $table->boolean('is_left')->default(false);
            $table->timestampTz('gone_at')->nullable();              // no longer in billing
            $table->string('source', 20)->default('desk');
            $table->timestampsTz();
            $table->unique(['company_id', 'header_id']);
            $table->index(['company_id', 'customer_code']);
            $table->index(['company_id', 'status']);
        });

        // the current due of each customer, written with the ledger in one transaction: nobody sums the ledger to read it
        Schema::create('desk_customer_balances', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_ref')->primary();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->decimal('balance', 12, 2)->default(0);            // + due, - advance
            $table->date('last_bill_period')->nullable();
            $table->date('last_payment_on')->nullable();
            $table->decimal('billing_due', 12, 2)->nullable();        // billing's own figure at the last import (to compare)
            $table->boolean('has_history')->default(false);           // billing's bill list was copied for this customer
            $table->timestampTz('updated_at')->nullable();
            $table->index(['company_id', 'balance']);
        });

        Schema::create('desk_bill_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('bill_ref');
            $table->date('period_start');
            $table->decimal('old_amount', 12, 2)->nullable();
            $table->decimal('new_amount', 12, 2)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20)->default('desk');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'bill_ref']);
        });

        Schema::create('desk_billing_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);                               // import | partitions | generate | disable ...
            $table->string('status', 16)->default('running');         // running | done | failed
            $table->jsonb('stats')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->index(['company_id', 'kind', 'started_at']);
        });

        // plan 2.2; the defaults are how the billing software works today (Century Link's numbers, Zisan 4-5 Nov 2026)
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('billing_mode', 16)->default('ispdigital');            // ispdigital | shadow | desk
            $table->unsignedSmallInteger('history_months')->default(12);           // months of bills/payments in the ledger
            $table->string('bill_generation', 16)->default('month_start');         // 1 month_start | expire_date
            $table->boolean('waive_off_month')->default(true);                     // 2
            $table->boolean('desk_auto_approve')->default(true);                   // 4
            $table->boolean('expire_on_payment')->default(true);                   // 5
            $table->unsignedSmallInteger('expire_full_percent')->default(85);
            $table->unsignedSmallInteger('expire_max_days')->default(28);
            $table->boolean('expire_keep_if_early')->default(true);
            $table->boolean('auto_line')->default(true);                           // 6 Payment Status Wise
            $table->string('disable_when', 16)->default('next_day');               // same_day | next_day | after_days
            $table->unsignedSmallInteger('disable_after_days')->default(1);
            $table->unsignedSmallInteger('disable_hour')->default(11);             // Bangladesh hour 0-23
            $table->boolean('online_expire')->default(true);                       // 7
            $table->unsignedSmallInteger('mikrotik_retries')->default(3);          // 8
            $table->unsignedSmallInteger('enable_min_percent')->default(90);       // 9
            $table->unsignedSmallInteger('partial_redisable_percent')->default(40); // 10
            $table->unsignedSmallInteger('partial_redisable_days')->default(3);
            $table->unsignedSmallInteger('activity_log_months')->nullable();       // null = keep for ever
            $table->timestampsTz();
        });

        Schema::create('billing_settings_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field', 50);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
        });
    }

    private function functions(): void
    {
        // monthly partitions <parent>_YYYYMM for [from, to); existing ones are left alone
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION desk_month_partitions(parent text, from_month date, to_month date) RETURNS integer
LANGUAGE plpgsql AS $$
DECLARE m date := date_trunc('month', from_month)::date; made integer := 0; name text;
BEGIN
  WHILE m < to_month LOOP
    name := parent || '_' || to_char(m, 'YYYYMM');
    IF to_regclass(name) IS NULL THEN
      EXECUTE format('CREATE TABLE %I PARTITION OF %I FOR VALUES FROM (%L) TO (%L)', name, parent, m, (m + interval '1 month')::date);
      made := made + 1;
    END IF;
    m := (m + interval '1 month')::date;
  END LOOP;
  RETURN made;
END $$;
SQL);
    }

    private function triggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION activity_log_hash(r activity_logs) RETURNS varchar LANGUAGE sql IMMUTABLE AS $$
  SELECT encode(sha256(convert_to(concat_ws('|', coalesce(r.prev_hash, ''), r.seq, r.company_id, coalesce(r.user_id::text, ''),
    r.actor, r.source, r.action, coalesce(r.subject_type, ''), coalesce(r.subject_id, ''), coalesce(r.customer_code, ''),
    coalesce(r.changes::text, ''), coalesce(r.note, ''), to_char(r.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US')),
    'UTF8')), 'hex')
$$;

-- one writer per company at a time, so every row links to the one before it
CREATE OR REPLACE FUNCTION activity_logs_chain() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  PERFORM pg_advisory_xact_lock(717171, NEW.company_id::integer);
  SELECT hash, seq + 1 INTO NEW.prev_hash, NEW.seq FROM activity_logs WHERE company_id = NEW.company_id
    ORDER BY seq DESC NULLS LAST LIMIT 1;
  NEW.seq := coalesce(NEW.seq, 1);
  NEW.hash := activity_log_hash(NEW);
  RETURN NEW;
END $$;
CREATE TRIGGER activity_logs_chain BEFORE INSERT ON activity_logs FOR EACH ROW EXECUTE FUNCTION activity_logs_chain();

CREATE OR REPLACE FUNCTION activity_logs_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'activity_logs is append-only';
END $$;
CREATE TRIGGER activity_logs_append_only BEFORE UPDATE OR DELETE ON activity_logs FOR EACH ROW EXECUTE FUNCTION activity_logs_append_only();
CREATE TRIGGER activity_logs_no_truncate BEFORE TRUNCATE ON activity_logs FOR EACH STATEMENT EXECUTE FUNCTION activity_logs_append_only();

-- the Desk's own ledger rows are never changed or removed (a wrong row gets a reverse row); only the imported copy of
-- billing (source 'ispdigital') is refreshed while billing is the master
CREATE OR REPLACE FUNCTION desk_ledger_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.source <> 'ispdigital' THEN
    RAISE EXCEPTION 'desk_ledger_entries: Desk rows are never changed; add a reverse row';
  END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER desk_ledger_guard BEFORE UPDATE OR DELETE ON desk_ledger_entries FOR EACH ROW EXECUTE FUNCTION desk_ledger_guard();
SQL);
    }

    public function down(): void
    {
        foreach (['billing_settings_log', 'billing_settings', 'desk_billing_runs', 'desk_bill_versions', 'desk_customer_balances',
            'desk_customers', 'desk_packages'] as $t) {
            Schema::dropIfExists($t);
        }
        foreach (array_reverse(array_keys($this->partitioned())) as $t) {
            DB::statement("DROP TABLE IF EXISTS {$t} CASCADE");
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS activity_logs_chain() CASCADE; DROP FUNCTION IF EXISTS activity_logs_append_only() CASCADE;
                DROP FUNCTION IF EXISTS desk_ledger_guard() CASCADE; DROP FUNCTION IF EXISTS activity_log_hash(activity_logs) CASCADE;
                DROP FUNCTION IF EXISTS desk_month_partitions(text, date, date)');
        }
    }
};
