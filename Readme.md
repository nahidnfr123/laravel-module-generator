# Laravel Module Generator

A developer-friendly Laravel package to generate complete modules (Model, Migration, Controller, Service, Resource, Collection, Form Request, and Routes) from a single YAML configuration file. Supports custom stub overrides, allowing you to keep your codebase clean and consistent.

---

## ✨ Features

- Generate full Laravel modules from YAML configuration
- Customizable stub support (with fallback to internal defaults)
- Generates:
  - Models with relationships
  - Database migrations
  - API Controllers
  - Service classes
  - Form Request validation
  - API Resources & Collections
  - Route entries
- Smart fillable and relationship handling
- Designed for rapid development and prototyping

---

## 🚀 Installation

Install the package via Composer:

```bash
composer require nahid-ferdous/laravel-module-generator --dev
```

## 📂 Optional: Publish Config & Stubs

You may publish the configuration and stub files to customize them. If you don't publish them, the package will use its built-in defaults automatically.

```bash
# Publish configuration file
php artisan vendor:publish --tag=module-generator-config

# Publish stub files for customization
php artisan vendor:publish --tag=module-generator-stubs
```

This will publish:
- **Config**: `config/module-generator.php`
- **Stubs**: `module/stub/`

---

## 🛠️ Usage

### 1. Create Your YAML Configuration

Create a YAML file at the default path: `module/models.yaml`

Define your models with their fields, validation rules, and relationships:

**Example: `module/models.yaml`**

```yaml
User:
  fields:
    name: string:unique
    email: string:unique
    password: string
    is_active: boolean:default true

Unit:
  fields:
    name: string:unique
    code: string:nullable
    description: string
    is_active: boolean:default true
    created_by: foreignId:users:nullable
    updated_by: foreignId:users:nullable
  relations:
    creator:
      type: belongsTo
      model: User
    updater:
      type: belongsTo
      model: User

UnitConversion:
  fields:
    from_unit_id: foreignId:units
    to_unit_id: foreignId:units
    multiplier: double:default 1
  relations:
    from_unit:
      type: belongsTo
      model: Unit
    to_unit:
      type: belongsTo
      model: Unit
  unique:
    - [from_unit_id, to_unit_id]
```

### 2. Generate Your Module

Generate the complete module structure with:

```bash
php artisan module:generate
```

Use `--force` to overwrite existing files:

```bash
php artisan module:generate --force
```

---

## 🧪 What Gets Generated

For each model defined in your YAML file, the package will generate:

- ✅ **Eloquent Model** → `app/Models/`
- ✅ **Migration** → `database/migrations/`
- ✅ **API Controller** → `app/Http/Controllers/`
- ✅ **Service Class** → `app/Services/`
- ✅ **Form Request** → `app/Http/Requests/`
- ✅ **API Resource** → `app/Http/Resources/`
- ✅ **Resource Collection** → `app/Http/Resources/`
- ✅ **Route Registration** → `routes/api.php`

---

## 🧱 Stub Customization

This package allows you to override any of the stubs it uses for complete customization of generated code.

### Default Stub Configuration

```php
'stubs' => [
    'model' => 'model.stub',
    'controller' => 'controller.stub',
    'service' => 'service.stub',
    'repository' => 'repository.stub',
    'migration' => 'migration.stub',
    'request' => 'request.stub',
    'collection' => 'collection.stub',
    'resource' => 'resource.stub',
],
```

### Customizing Stubs

If you published the stubs with:
```bash
php artisan vendor:publish --tag=module-generator-stubs
```

You can edit them at: `module/stub/`

Each stub file uses placeholders that get replaced during generation, allowing you to maintain consistency across your entire codebase.

---

## ⚙️ Configuration

To change the YAML path or customize stub names, update `config/module-generator.php`:

```php
<?php

return [
    'default_path' => base_path('module'),
    'models_path' => base_path('module/models.yaml'),
    'stubs' => [
        'model' => 'model.stub',
        'controller' => 'controller.stub',
        'service' => 'service.stub',
        'repository' => 'repository.stub',
        'migration' => 'migration.stub',
        'request' => 'request.stub',
        'collection' => 'collection.stub',
        'resource' => 'resource.stub',
    ],
];
```

---

## 📝 YAML Schema Guide

### Field Types
- `string` - VARCHAR field
- `string:unique` - VARCHAR with unique constraint
- `string:nullable` - Nullable VARCHAR
- `boolean:default true` - Boolean with default value
- `foreignId:table_name` - Foreign key reference
- `double` - Double/float field

### Relationship Types
- `belongsTo` - Belongs to relationship
- `hasMany` - Has many relationship
- `hasOne` - Has one relationship
- `belongsToMany` - Many-to-many relationship

### Unique Constraints
Define composite unique constraints:
```yaml
unique:
  - [field1, field2]
  - [field3, field4, field5]
```

---

## 🔄 Versioning

This package follows [Semantic Versioning](https://semver.org/). Use tags like `v1.0.0`, `v1.1.0`, etc., when pushing updates to Packagist.

---

## 🤝 Contributing

Pull requests are welcome! For major changes, please open an issue first to discuss what you would like to change.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## 📄 License

This package is open-sourced software licensed under the [MIT license](LICENSE).

---

## 🙏 Credits

Created and maintained by **[Nahid Ferdous](https://github.com/nahidnfr123)**

Special thanks to the Laravel community and all contributors who help improve this package.

---

## 🐛 Issues & Support

If you encounter any issues or have questions:

1. Check the [existing issues](https://github.com/nahid-ferdous/laravel-module-generator/issues)
2. Create a new issue with detailed information
3. Include your YAML configuration and error messages

---

## 🚀 Roadmap

- [ ] Support for additional relationship types
- [ ] GUI for YAML configuration
- [ ] Integration with Laravel Sanctum
- [ ] Custom validation rule generation
- [ ] Support for nested resources

---

*Happy coding! 🎉*