# Property request decisions

When an owner accepts a request and assigns a lawyer, it becomes
`pending_lawyer_review`. The owner cannot accept or reject any other request
for that property while a request is `pending_lawyer_review` or `accepted`.
Those attempts return HTTP 409. Other requests keep their existing statuses.
Property-level transaction locks serialize decisions, and a database unique
index prevents multiple selected requests for the same property.

## Cancellation

`PATCH /api/v1/properties/requests/{propertyRequest}/cancel`

Either the authenticated owner or requester can cancel a rent or purchase
request regardless of its request status, until the lawyer, owner, and
requester have all approved the associated contract. No body is required.
The response contains `message` and `data`, with `data.status` set to
`cancelled`. An unrelated user (including the assigned lawyer) receives
HTTP 403. All three contract approvals make cancellation return HTTP 409.
Repeating an already completed cancellation succeeds without changing its reason.

Accepting the request or creating a contract does not prevent cancellation.
If a contract exists before the approval cutoff, cancellation also cancels
that contract and outstanding AI work in the same transaction. The property
returns from `under_contract` to `published`, allowing the owner to decide
other requests. Existing contract transition and payment guards remain in
place for records already beyond the approval stage. Lawyer rejection also
releases the selection.

## Rental overlap

A rental contract becoming `active` marks its property `rented`. Whenever a
property transitions to `rented`, other open rental requests with overlapping
terms become `cancelled`, with a reason in `response_note`. The accepted
request, terminal requests, other properties, and non-overlapping terms are
preserved. Use the agreed contract dates, falling back to the accepted
request's dates if no contract exists.

Terms are half-open: `term_start < rental_end AND term_end > rental_start`.
A rental ending on the day another begins does not overlap.

## Deployment

Run `php artisan migrate` to install the selection index and lifecycle
triggers. If historical data contains multiple selected requests for a
property, the migration fails rather than choosing one or cancelling data.
Resolve those selections deliberately before retrying. The migration applies
rental cancellation on subsequent status transitions; it does not rewrite
historical rented properties.
