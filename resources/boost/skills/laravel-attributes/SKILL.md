---
name: laravel-attributes
description: >-
  Rules for Laravel Eloquent Attribute::make() syntax. Activate when writing or
  refactoring model accessors/mutators, or when reviewing Eloquent models.
---
# Laravel Modern Attributes Rules

## Overview
This document defines the rules for using modern Laravel Attribute syntax in this project. All Eloquent model accessors should use the modern `Attribute::make()` syntax instead of the legacy `getXxxAttribute()` methods.

## Modern Attribute Syntax Rules

### 1. Use `Attribute::make()` Instead of Legacy Accessors

**❌ Avoid (Legacy Syntax):**
```php
public function getFullNameAttribute(): string
{
    return $this->first_name . ' ' . $this->last_name;
}
```

**✅ Use (Modern Syntax):**
```php
protected function fullName(): Attribute
{
    return Attribute::make(
        get: function ($value, $attributes): string {
            return $this->first_name . ' ' . $this->last_name;
        }
    );
}
```

### 2. Required Import Statement

Always include the Attribute import at the top of your model files:

```php
use Illuminate\Database\Eloquent\Casts\Attribute;
```

### 3. Method Naming Convention

- **Method name:** Use camelCase without the "get" prefix
- **Accessor name:** Laravel automatically converts to snake_case for access

Examples:
- `fullName()` → accessible as `$model->full_name`
- `fullAddress()` → accessible as `$model->full_address`
- `isActive()` → accessible as `$model->is_active`

### 4. Method Visibility

Always use `protected` visibility for attribute methods:

```php
protected function fullName(): Attribute
{
    return Attribute::make(
        get: fn($value, $attributes) => $this->first_name . ' ' . $this->last_name
    );
}
```

### 5. Return Type Declaration

Always include the return type declaration:

```php
protected function fullName(): Attribute
```

### 6. Closure Parameters

The closure receives two parameters:
- `$value`: The raw database value (usually null for computed attributes)
- `$attributes`: Array of all model attributes

```php
protected function fullAddress(): Attribute
{
    return Attribute::make(
        get: function ($value, $attributes): string {
            $parts = [
                $this->street,
                $this->city,
                $this->postal_code,
            ];
            
            return implode(', ', array_filter($parts));
        }
    );
}
```

### 7. Using Short Closures (Arrow Functions)

For simple attributes, prefer arrow functions:

```php
protected function fullName(): Attribute
{
    return Attribute::make(
        get: fn($value, $attributes) => $this->first_name . ' ' . $this->last_name
    );
}
```

### 8. Mutators (Setters)

When you need to set values, use the `set` parameter:

```php
protected function email(): Attribute
{
    return Attribute::make(
        get: fn($value) => strtolower($value),
        set: fn($value) => strtolower($value)
    );
}
```

### 9. Position in Class Structure

Place attribute methods after relationships and before scopes:

```php
class User extends Model
{
    protected $fillable = [...];
    
    protected function casts(): array { ... }
    
    // Relationships
    public function posts(): HasMany { ... }
    
    // Attributes
    protected function fullName(): Attribute { ... }
    
    // Scopes
    public function scopeActive($query) { ... }
}
```

## Migration Guidelines

### When Refactoring Legacy Accessors

1. **Remove the old method:**
   ```php
   // Remove this
   public function getFullNameAttribute(): string { ... }
   ```

2. **Add the modern method:**
   ```php
   // Add this
   protected function fullName(): Attribute { ... }
   ```

3. **Add the import if not present:**
   ```php
   use Illuminate\Database\Eloquent\Casts\Attribute;
   ```

4. **Test the functionality** to ensure it works the same way

## Benefits of Modern Syntax

- **Better Performance:** More efficient than legacy accessors
- **Type Safety:** Better IDE support and static analysis
- **Consistency:** Aligns with Laravel's modern patterns
- **Flexibility:** Supports both getters and setters in one method
- **Future-Proof:** Laravel's recommended approach going forward

## Examples from This Project

### Document Model
```php
protected function fullNumber(): Attribute
{
    return Attribute::make(
        get: function ($value, $attributes): string {
            if ($this->series) {
                return $this->series . ' ' . $this->number;
            }
            return $this->number;
        }
    );
}
```

### PersonalInfo Model
```php
protected function fullName(): Attribute
{
    return Attribute::make(
        get: function ($value, $attributes): string {
            $parts = [
                $this->last_name,
                $this->first_name,
                $this->middle_name,
            ];
            return implode(' ', array_filter($parts));
        }
    );
}
```

### Address Model
```php
protected function fullAddress(): Attribute
{
    return Attribute::make(
        get: function ($value, $attributes): string {
            $parts = [
                $this->postal_code,
                $this->city?->name,
                $this->street,
                $this->house,
            ];
            return implode(', ', array_filter($parts));
        }
    );
}
```

## Enforcement

- All new accessors MUST use modern syntax
- Legacy accessors should be refactored when models are updated
- Code reviews should check for compliance with these rules
- Use Laravel Pint for automatic formatting

## References

- [Laravel Documentation: Accessors & Mutators](https://laravel.com/docs/11.x/eloquent-mutators#accessors-and-mutators)
- [Laravel Documentation: Attribute Casting](https://laravel.com/docs/11.x/eloquent-mutators#attribute-casting)
