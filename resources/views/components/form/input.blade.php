@props(['name', 'value' => null, 'type' => 'text', 'id' => null])

{{-- Keeps the submitted value after a failed validation (except passwords). --}}
<input type="{{ $type }}" name="{{ $name }}" id="{{ $id ?? $name }}"
    @unless ($type === 'password') value="{{ old($name, $value) }}" @endunless
    @error($name) aria-invalid="true" @enderror
    {{ $attributes->class(['form-control', 'form-control-invalid' => $errors->has($name)]) }}>
