@props(['name', 'id' => null])

<select name="{{ $name }}" id="{{ $id ?? $name }}" @error($name) aria-invalid="true" @enderror
    {{ $attributes->class(['form-control', 'form-control-invalid' => $errors->has($name)]) }}>
    {{ $slot }}
</select>
