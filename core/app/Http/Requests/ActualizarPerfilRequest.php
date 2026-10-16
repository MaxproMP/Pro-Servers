<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Datos personales editables desde Mi cuenta (vale igual para cuentas creadas con Google, GitHub o correo). */
class ActualizarPerfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => strtolower(ltrim(trim((string) $this->input('username')), '@')),
            'nombre' => trim((string) $this->input('nombre')),
            'apellido' => trim((string) $this->input('apellido')) ?: null,
            'phone' => trim((string) $this->input('phone')) ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:60', "regex:/^[\\p{L}][\\p{L} '\\-]*$/u"],
            'apellido' => ['nullable', 'string', 'max:60', "regex:/^[\\p{L}][\\p{L} '\\-]*$/u"],
            // UNIQUE de la tabla: incluye cuentas dadas de baja (borrado lógico).
            'username' => ['required', 'string', 'regex:/^[a-z0-9_.]{3,24}$/', Rule::unique('clientes', 'username')->ignore($this->user()->id)],
            'phone' => ['nullable', 'string', 'regex:/^\\+?[0-9 ()\\-]{6,24}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.regex' => 'El nombre solo admite letras, espacios, apóstrofos y guiones.',
            'apellido.regex' => 'El apellido solo admite letras, espacios, apóstrofos y guiones.',
            'username.regex' => 'El usuario lleva de 3 a 24 caracteres: letras minúsculas, números, punto o guion bajo.',
            'username.unique' => 'Ese nombre de usuario ya está en uso.',
            'phone.regex' => 'Escribí el teléfono con código de área, por ejemplo +54 9 11 1234-5678.',
        ];
    }
}
