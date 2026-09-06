<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Module 8 (FR-8.1 .. FR-8.4).
     *
     * MVP delivers email only (SRS 2.9). The channel enum carries the Phase 2
     * values now so the notification history stays representable when push and
     * SMS arrive, without a migration that rewrites existing rows.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create type app.notification_channel as enum ('email', 'in_app', 'push', 'sms');
            create type app.notification_status  as enum ('queued', 'sending', 'sent', 'delivered', 'failed', 'read');

            create table app.notification_types (
                code         varchar(64) primary key,
                name_ar      varchar(191) not null,
                name_en      varchar(191) not null,
                is_mandatory boolean not null default false,

                constraint notification_types_code_format check (code ~ '^[a-z_]+\.[a-z_]+$')
            );

            comment on column app.notification_types.is_mandatory is
              'Mandatory types ignore user preferences. Contract signature requests and payment confirmations are legal notices, not marketing.';

            insert into app.notification_types (code, name_ar, name_en, is_mandatory) values
                ('account.email_verification', 'تأكيد البريد الإلكتروني', 'Email verification',      true),
                ('account.password_reset',     'إعادة تعيين كلمة المرور', 'Password reset',          true),
                ('identity.approved',          'اعتماد الهوية',           'Identity approved',       false),
                ('identity.rejected',          'رفض الهوية',              'Identity rejected',       true),
                ('property.approved',          'اعتماد العقار',           'Property approved',       false),
                ('request.received',           'طلب جديد',                'New request received',    false),
                ('request.accepted',           'قبول الطلب',              'Request accepted',        false),
                ('request.rejected',           'رفض الطلب',               'Request rejected',        false),
                ('contract.analysis_ready',    'اكتمال تحليل العقد',      'Contract analysis ready', false),
                ('contract.lawyer_assigned',   'تعيين محامٍ',             'Lawyer assigned',         false),
                ('contract.approved',          'اعتماد العقد',            'Contract approved',       true),
                ('contract.signature_request', 'طلب توقيع',               'Signature requested',     true),
                ('contract.fully_signed',      'اكتمال التوقيعات',        'Contract fully signed',   true),
                ('payment.confirmed',          'تأكيد الدفع',             'Payment confirmed',       true),
                ('handover.confirmed',         'تأكيد الاستلام',          'Handover confirmed',      true),
                ('contract.cancelled',         'إلغاء العقد',             'Contract cancelled',      true);

            create table app.notifications (
                id           uuid primary key default app.uuid_generate_v7(),
                tenant_id    uuid not null references app.tenants (id) on delete restrict,
                user_id      uuid not null references app.users (id) on delete cascade,
                type_code    varchar(64) not null references app.notification_types (code) on delete restrict,
                channel      app.notification_channel not null,
                locale       app.locale not null default 'ar',
                subject      varchar(255),
                body         text not null,
                payload      jsonb not null default '{}'::jsonb,
                status       app.notification_status not null default 'queued',
                attempts     smallint not null default 0,
                sent_at      timestamptz,
                read_at      timestamptz,
                failure_reason text,
                created_at   timestamptz not null default now(),

                constraint notifications_sent_has_timestamp check (
                    status not in ('sent', 'delivered', 'read') or sent_at is not null
                )
            );

            create index notifications_user_idx    on app.notifications (user_id, created_at desc);
            create index notifications_unread_idx  on app.notifications (user_id) where read_at is null;
            create index notifications_pending_idx on app.notifications (status, created_at)
                where status in ('queued', 'sending');

            create table app.notification_preferences (
                user_id    uuid not null references app.users (id) on delete cascade,
                type_code  varchar(64) not null references app.notification_types (code) on delete cascade,
                channel    app.notification_channel not null,
                is_enabled boolean not null default true,

                primary key (user_id, type_code, channel)
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop table if exists app.notification_preferences;
            drop table if exists app.notifications;
            drop table if exists app.notification_types;
            drop type if exists app.notification_status;
            drop type if exists app.notification_channel;
        SQL);
    }
};
