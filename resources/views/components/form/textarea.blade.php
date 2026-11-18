@props(['name', 'value' => null, 'id' => null, 'rows' => 3])

<textarea name="{{ $name }}" id="{{ $id ?? $name }}" rows="{{ $rows }}" @error($name) aria-invalid="true" @enderror
    {{ $attributes->class(['form-control', 'form-control-invalid' => $errors->has($name)]) }}>{{ old($name, $value) }}</textarea>
