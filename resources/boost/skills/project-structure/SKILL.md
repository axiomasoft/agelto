---
description: Guidelines for organizing the project structure, emphasizing grouping by domain/feature
globs: app/**/*
---

# Project Structure & Organization

## Grouping by Domain

- **Avoid Flat Structures**: Do not dump all classes into a single directory (e.g., `app/Models`, `app/Services`).
- **Group by Feature/Domain**: Create subdirectories that group related classes together.
  - **Models**: `app/Models/Order/Order.php`, `app/Models/Order/OrderItem.php`.
  - **Services**: `app/Services/Payment/StripeService.php`, `app/Services/Payment/PayPalService.php`.
  - **DTOs**: `app/Dto/Estimates/Estimate.php`, `app/Dto/Estimates/Item.php`.

## Namespace Convention

- **Reflect Directory Structure**: Namespaces must strictly follow the directory structure.
  - File: `app/Models/Order/Order.php` -> Namespace: `App\Models\Order`.
- **Generation**: When using artisan commands, include the folder path.
  - `php artisan make:model Order/Order` (creates `app/Models/Order/Order.php`).

## Naming Conventions

- **Console Commands**: Class names for console commands must end with `Command`.
  - Example: `EisMapCommand.php` instead of `EisMap.php`.

## Benefits

- **Navigability**: Easier to find related files.
- **Scalability**: Prevents directories from becoming cluttered as the project grows.
- **Context**: Provides immediate context about what a class belongs to.
