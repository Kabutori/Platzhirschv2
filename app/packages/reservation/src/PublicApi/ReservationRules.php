<?php
namespace App\Modules\Reservation\PublicApi;
use Illuminate\Validation\Rule;
class ReservationRules
{
    public static function rules(): array
    {
        return [
            'additional_table_ids' => 'sometimes|array|max:9',
            'additional_table_ids.*' => 'integer|distinct',
            'table_id' => 'required|integer',
            'guest_name' => 'required|string|max:120',
            'email' => 'nullable|email|max:254',
            'phone' => 'nullable|string|max:50',
            'party_size' => 'required|integer|min:1|max:50',
            'starts_at' => 'required|date_format:Y-m-d\TH:i',
            'duration_minutes' => 'required|integer|min:15|max:360',
            'status' => ['sometimes', Rule::in(['confirmed', 'seated', 'completed', 'cancelled', 'no_show'])],
            'notes' => 'nullable|string|max:2000',
            'request_key' => 'nullable|uuid',
            'version' => 'nullable|integer|min:1',
        ];
    }
}
