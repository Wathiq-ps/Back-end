<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately fail on conflicting existing selections; never silently
        // cancel a request or contract to make historical data fit this rule.
        DB::unprepared(<<<'SQL'
            create unique index requests_one_selected_per_property
                on app.property_requests (property_id)
                where status in ('pending_lawyer_review', 'accepted');

            create function app.cancel_overlapping_rental_requests()
            returns trigger language plpgsql as $$
            declare
                selected_request uuid;
                rental_start date;
                rental_end date;
            begin
                if new.status <> 'rented' or old.status = 'rented' then
                    return new;
                end if;

                select r.id, coalesce(c.starts_on, r.term_start), coalesce(c.ends_on, r.term_end)
                  into selected_request, rental_start, rental_end
                  from app.property_requests r
                  left join app.contracts c on c.request_id = r.id and c.tenant_id = r.tenant_id
                 where r.property_id = new.id and r.tenant_id = new.tenant_id
                   and r.status = 'accepted' and r.type = 'rent';

                if selected_request is null or rental_start is null or rental_end is null then
                    raise exception 'A rented property requires an accepted rental request with dates'
                        using errcode = 'restrict_violation';
                end if;

                -- Half-open terms: an end date can be the next rental's start.
                update app.property_requests
                   set status = 'cancelled',
                       response_note = 'Property rented for an overlapping period.'
                 where property_id = new.id and tenant_id = new.tenant_id
                   and id <> selected_request and type = 'rent'
                   and status in ('pending', 'pending_lawyer_review')
                   and term_start < rental_end and term_end > rental_start;
                return new;
            end;
            $$;

            create trigger properties_cancel_overlapping_requests
                after update of status on app.properties
                for each row execute function app.cancel_overlapping_rental_requests();

            create function app.sync_contract_request_lifecycle()
            returns trigger language plpgsql as $$
            begin
                if new.status = old.status then
                    return new;
                end if;

                if new.status = 'cancelled' then
                    -- Use the same property-first lock order as request decisions.
                    perform 1 from app.properties where id = new.property_id for update;
                    update app.property_requests
                       set status = 'cancelled', response_note = new.cancellation_reason
                     where id = new.request_id and tenant_id = new.tenant_id and status = 'accepted';
                    update app.properties
                       set status = 'published'
                     where id = new.property_id and tenant_id = new.tenant_id
                       and status in ('under_contract', 'rented');
                elsif new.status = 'active' and new.type = 'rent' then
                    -- Activation follows payment verification in the contract state machine.
                    update app.properties set status = 'rented'
                     where id = new.property_id and tenant_id = new.tenant_id;
                end if;
                return new;
            end;
            $$;

            create trigger contracts_sync_request_lifecycle
                after update of status on app.contracts
                for each row execute function app.sync_contract_request_lifecycle();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists contracts_sync_request_lifecycle on app.contracts;
            drop function if exists app.sync_contract_request_lifecycle();
            drop trigger if exists properties_cancel_overlapping_requests on app.properties;
            drop function if exists app.cancel_overlapping_rental_requests();
            drop index if exists app.requests_one_selected_per_property;
        SQL);
    }
};
