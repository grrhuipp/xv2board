<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class DeviceSelfServiceVerify extends FormRequest
{
    public function rules()
    {
        return [
            'email' => 'required|email:strict',
            'email_code' => 'required'
        ];
    }

    public function messages()
    {
        return [
            'email.required' => __('Email can not be empty'),
            'email.email' => __('Email format is incorrect'),
            'email_code.required' => __('Email verification code cannot be empty')
        ];
    }
}
