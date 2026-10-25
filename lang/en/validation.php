<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'Please tick the box for :attribute.',
    'accepted_if' => 'The :attribute field must be accepted when :other is :value.',
    'active_url' => 'The :attribute field must be a valid URL.',
    'after' => 'Please enter a :attribute that is after :date.',
    'after_or_equal' => 'Please enter a :attribute that is on or after :date.',
    'alpha' => 'The :attribute can only have letters.',
    'alpha_dash' => 'The :attribute can only have letters, numbers, dashes and underscores.',
    'alpha_num' => 'The :attribute can only have letters and numbers.',
    'any_of' => 'The :attribute field is invalid.',
    'array' => 'The :attribute field must be an array.',
    'array_keys' => 'The :attribute field must only contain the following keys: :values.',
    'ascii' => 'The :attribute field must only contain single-byte alphanumeric characters and symbols.',
    'base64' => 'The :attribute field must be a valid Base64 string.',
    'before' => 'Please enter a :attribute that is before :date.',
    'before_or_equal' => 'Please enter a :attribute that is not later than :date.',
    'between' => [
        'array' => 'Please choose between :min and :max for :attribute.',
        'file' => 'The :attribute file must be between :min and :max kilobytes in size.',
        'numeric' => 'Please enter a :attribute between :min and :max.',
        'string' => 'The :attribute needs between :min and :max characters.',
    ],
    'boolean' => 'Please choose yes or no for :attribute.',
    'can' => 'The :attribute field contains an unauthorized value.',
    'confirmed' => 'The two :attribute entries do not match. Please type it again.',
    'contains' => 'The :attribute field is missing a required value.',
    'current_password' => 'That password is not correct. Please try again.',
    'date' => 'Please enter a real date for :attribute.',
    'date_equals' => 'The :attribute field must be a date equal to :date.',
    'date_format' => 'Please write the :attribute in this form: :format.',
    'decimal' => 'The :attribute field must have :decimal decimal places.',
    'declined' => 'The :attribute field must be declined.',
    'declined_if' => 'The :attribute field must be declined when :other is :value.',
    'different' => 'The :attribute and the :other cannot be the same. Please choose two different ones.',
    'digits' => 'The :attribute needs exactly :digits digits.',
    'digits_between' => 'The :attribute field must be between :min and :max digits.',
    'dimensions' => 'The :attribute field has invalid image dimensions.',
    'distinct' => 'The same :attribute appears more than once.',
    'doesnt_contain' => 'The :attribute field must not contain any of the following: :values.',
    'doesnt_end_with' => 'The :attribute field must not end with one of the following: :values.',
    'doesnt_start_with' => 'The :attribute field must not start with one of the following: :values.',
    'email' => 'Please enter a real email address, like name@example.com.',
    'encoding' => 'The :attribute field must be encoded in :encoding.',
    'ends_with' => 'The :attribute field must end with one of the following: :values.',
    'enum' => 'Please choose a :attribute from the list.',
    'exists' => 'Please choose a :attribute from the list.',
    'extensions' => 'The :attribute field must have one of the following extensions: :values.',
    'file' => 'The :attribute field must be a file.',
    'filled' => 'Please fill in the :attribute.',
    'gt' => [
        'array' => 'Please choose more than :value for :attribute.',
        'file' => 'The :attribute field must be greater than :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than :value.',
        'string' => 'The :attribute field must be greater than :value characters.',
    ],
    'gte' => [
        'array' => 'The :attribute field must have :value items or more.',
        'file' => 'The :attribute field must be greater than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than or equal to :value.',
        'string' => 'The :attribute field must be greater than or equal to :value characters.',
    ],
    'hex_color' => 'The :attribute field must be a valid hexadecimal color.',
    'image' => 'The :attribute field must be an image.',
    'in' => 'Please choose a :attribute from the list.',
    'in_array' => 'The :attribute field must exist in :other.',
    'in_array_keys' => 'The :attribute field must contain at least one of the following keys: :values.',
    'integer' => 'The :attribute must be a whole number.',
    'ip' => 'The :attribute field must be a valid IP address.',
    'ipv4' => 'The :attribute field must be a valid IPv4 address.',
    'ipv6' => 'The :attribute field must be a valid IPv6 address.',
    'json' => 'The :attribute field must be a valid JSON string.',
    'list' => 'Something went wrong with :attribute. Please try again.',
    'lowercase' => 'Please write the :attribute in small letters.',
    'lt' => [
        'array' => 'The :attribute field must have less than :value items.',
        'file' => 'The :attribute field must be less than :value kilobytes.',
        'numeric' => 'The :attribute field must be less than :value.',
        'string' => 'The :attribute field must be less than :value characters.',
    ],
    'lte' => [
        'array' => 'The :attribute field must not have more than :value items.',
        'file' => 'The :attribute field must be less than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be less than or equal to :value.',
        'string' => 'The :attribute field must be less than or equal to :value characters.',
    ],
    'mac_address' => 'The :attribute field must be a valid MAC address.',
    'max' => [
        'array' => 'Please choose no more than :max for :attribute.',
        'file' => 'The :attribute file is too big. The most allowed is :max kilobytes.',
        'numeric' => 'The :attribute cannot be more than :max.',
        'string' => 'The :attribute is too long. Please use :max characters or fewer.',
    ],
    'max_digits' => 'The :attribute field must not have more than :max digits.',
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'mimetypes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'Please choose at least :min for :attribute.',
        'file' => 'The :attribute file is too small. It needs at least :min kilobytes.',
        'numeric' => 'The :attribute must be at least :min.',
        'string' => 'The :attribute needs at least :min characters.',
    ],
    'min_digits' => 'The :attribute field must have at least :min digits.',
    'missing' => 'The :attribute field must be missing.',
    'missing_if' => 'The :attribute field must be missing when :other is :value.',
    'missing_unless' => 'The :attribute field must be missing unless :other is :value.',
    'missing_with' => 'The :attribute field must be missing when :values is present.',
    'missing_with_all' => 'The :attribute field must be missing when :values are present.',
    'multiple_of' => 'The :attribute field must be a multiple of :value.',
    'not_in' => 'Please choose a :attribute from the list.',
    'not_regex' => 'The :attribute is not written in a form we can accept. Please check it.',
    'numeric' => 'The :attribute must be a number.',
    'password' => [
        'letters' => 'The :attribute needs at least one letter.',
        'mixed' => 'The :attribute needs at least one capital letter and one small letter.',
        'numbers' => 'The :attribute needs at least one number.',
        'symbols' => 'The :attribute needs at least one symbol, like ! or #.',
        'uncompromised' => 'This :attribute has appeared in a public data leak, so it is not safe. Please choose a different one.',
    ],
    'password_reused' => 'Please choose a password you have not been using.',
    'present' => 'Please fill in the :attribute.',
    'present_if' => 'The :attribute field must be present when :other is :value.',
    'present_unless' => 'The :attribute field must be present unless :other is :value.',
    'present_with' => 'The :attribute field must be present when :values is present.',
    'present_with_all' => 'The :attribute field must be present when :values are present.',
    'prohibited' => 'The :attribute field is prohibited.',
    'prohibited_if' => 'The :attribute field is prohibited when :other is :value.',
    'prohibited_if_accepted' => 'The :attribute field is prohibited when :other is accepted.',
    'prohibited_if_declined' => 'The :attribute field is prohibited when :other is declined.',
    'prohibited_unless' => 'The :attribute field is prohibited unless :other is in :values.',
    'prohibits' => 'The :attribute field prohibits :other from being present.',
    'regex' => 'The :attribute is not written in a form we can accept. Please check it.',
    'required' => 'Please fill in the :attribute.',
    'required_array_keys' => 'The :attribute field must contain entries for: :values.',
    'required_if' => 'Please fill in the :attribute.',
    'required_if_accepted' => 'Please fill in the :attribute.',
    'required_if_declined' => 'The :attribute field is required when :other is declined.',
    'required_unless' => 'Please fill in the :attribute.',
    'required_with' => 'Please fill in the :attribute.',
    'required_with_all' => 'The :attribute field is required when :values are present.',
    'required_without' => 'Please fill in the :attribute.',
    'required_without_all' => 'The :attribute field is required when none of :values are present.',
    'same' => 'The :attribute and the :other must be the same.',
    'size' => [
        'array' => 'The :attribute field must contain :size items.',
        'file' => 'The :attribute field must be :size kilobytes.',
        'numeric' => 'The :attribute field must be :size.',
        'string' => 'The :attribute needs exactly :size characters.',
    ],
    'starts_with' => 'The :attribute field must start with one of the following: :values.',
    'string' => 'Please write the :attribute as text.',
    'timezone' => 'The :attribute field must be a valid timezone.',
    'unique' => 'That :attribute is already used. Please use a different one.',
    'uploaded' => 'The :attribute could not be uploaded. Please try again.',
    'uppercase' => 'Please write the :attribute in capital letters.',
    'url' => 'The :attribute field must be a valid URL.',
    'ulid' => 'The :attribute field must be a valid ULID.',
    'uuid' => 'The :attribute field must be a valid UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'barangay_id' => ['required' => 'Please choose a barangay.'],
        'municipality_id' => ['required' => 'Please choose a municipality.'],
        'role' => ['required' => 'Please choose a role.'],
        'gender' => ['required' => 'Please choose male or female.'],
        'civil_status' => ['required' => 'Please choose a civil status.'],
        'household_id' => ['required' => 'Please choose a household.'],
        'household_mode' => ['required' => 'Please choose a household option.'],
        'household_role' => ['required' => 'Please choose their place in the household.'],
        'event_id' => ['required' => 'Please choose a relief event.'],
        'allocation_type' => ['required' => 'Please choose who the relief is for.'],
        'status' => ['required' => 'Please choose a status.'],
        'path' => ['required' => 'Please choose how to register.'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [
        'name' => 'name',
        'email' => 'email address',
        'password' => 'password',
        'current_password' => 'current password',
        'password_confirmation' => 'password again',
        'first_name' => 'first name',
        'middle_name' => 'middle name',
        'last_name' => 'last name',
        'maiden_name' => 'maiden name',
        'suffix' => 'name ending (Jr., Sr.)',
        'birthdate' => 'birthdate',
        'deceased_at' => 'date of death',
        'gender' => 'sex',
        'civil_status' => 'civil status',
        'street_address' => 'street address',
        'barangay_id' => 'barangay',
        'municipality_id' => 'municipality',
        'household_id' => 'household',
        'household_mode' => 'household choice',
        'household_role' => 'place in the household',
        'is_head' => 'head of household',
        'father_id' => "father's record",
        'mother_id' => "mother's record",
        'spouse_id' => 'husband or wife',
        'dependent_ids' => 'children',
        'new_head_id' => 'new head of household',
        'takes_surname_id' => 'person using the surname',
        'claim_resident_id' => 'listed record',
        'consent' => 'consent',
        'reason' => 'reason',
        'role' => 'role',
        'title' => 'event title',
        'allocation_type' => 'type of distribution',
        'start_date' => 'start date',
        'end_date' => 'end date',
        'status' => 'status',
        'max_claims_per_household' => 'most members per household',
        'event_id' => 'relief event',
        'representative_name' => "collector's name",
        'representative_relationship' => "collector's relationship to the resident",
        'representative_verified' => 'ID and authorization check',
        'code' => 'code',
        'first_household_id' => 'first household',
        'second_household_id' => 'second household',
        'first' => 'first person',
        'second' => 'second person',
        'managed_barangay_ids' => 'barangays',
        'path' => 'registration path',
        'kind' => 'kind of link',
    ],

];
