<?php

namespace App\Http\Requests\Property;

use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;

class SubmitPropertyRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * type (sale/rent) is derived from the property, not sent by the
     * caller — see PropertyRequestService::submit(). A rent request needs a
     * term (app.property_requests: requests_rent_has_term); a sale request
     * must not carry one (requests_term_pair).
     */
    public function rules(): array
    {
        $isRent = Property::where('id', $this->route('id'))->value('listing_type') === 'rent';

        return [
            'message' => ['nullable', 'string', 'max:2000'],
            'term_start' => [$isRent ? 'required' : 'prohibited', 'date', 'after_or_equal:today'],
            'term_end' => [$isRent ? 'required' : 'prohibited', 'date', 'after:term_start'],
        ];
    }
}
