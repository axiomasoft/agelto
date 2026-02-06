---
description: Guidelines for database migrations and models, specifically regarding table name definition
globs: database/migrations/**/*.php, Modules/**/database/migrations/**/*.php
---

# Database Guidelines

## Migration & Model Workflow

1.  **Create Migration & Model**: When creating a new table, always create the corresponding Model immediately (or use `php artisan make:model Name -m` to do both).
2.  **Table Naming**: Use short prefixes for table names to group them logically (e.g., `geo_countries`, `sys_logs`, `ord_orders`).
3.  **Table Name Definition**: In the migration file, **do not hardcode the table name string**. Instead, define a private method `table()` that retrieves the table name from the Model instance. This applies to `Schema::create`, `Schema::dropIfExists`, and also `constrained()` in foreign keys.

### Example Migration Pattern

```php
use App\Models\Geo\Country; // Import the corresponding Model
use App\Models\Geo\Region;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();
            // Foreign key using model's table name
            $table->foreignId('region_id')->constrained((new Region())->getTable());
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }

    /**
     * Get the table name from the model.
     */
    private function table(): string
    {
        return (new Country())->getTable();
    }
};
```
