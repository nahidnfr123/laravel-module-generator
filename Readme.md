# Laravel Module Generator

A developer-friendly Laravel package to generate complete modules (Model, Migration, Controller, Service, Resource, Collection, Form Request, and Routes) from a single YAML configuration file. Now includes **Postman collection generation** and **DB diagram export** for streamlined API development and documentation.

---

## ✨ Features

- Generate full Laravel modules from YAML configuration
- Customizable stub support (with fallback to internal defaults)
- **🆕 Postman collection generation** for instant API testing
- **🆕 Database diagram export** compatible with [dbdiagram.io](https://dbdiagram.io)
- Generates:
  - Models with relationships
  - Database migrations
  - API Controllers
  - Service classes
  - Form Request validation
  - API Resources & Collections
  - Route entries
  - **Postman collection files**
  - **DB diagram files (.dbml)**
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
  # all the generatable modules are false, 
  # so the user model only generates the Postman collection and dbdiagram files
  generate:
    model: false,
    migration: false
    controller: false
    service: false
    request: false
    resource: true
    collection: false
  fields:
    name: string
    email: string:unique
    email_verified_at: dateTime:nullable
    password: string
    avatar: string:nullable
    status: boolean:default true
    last_login_at: timestamp:nullable

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
    - [ from_unit_id, to_unit_id ]
```

### 2. Generate Your Complete Module

Generate the complete module structure with all features:

```bash
php artisan module:generate
```

**Available Options:**

```bash
php artisan module:generate --force                                    # Overwrite existing files
php artisan module:generate --file=custom/path/models.yaml            # Use custom YAML file
php artisan module:generate --skip-postman                            # Skip Postman collection generation
php artisan module:generate --skip-dbdiagram                          # Skip DB diagram generation
php artisan module:generate --postman-base-url=https://api.myapp.com  # Custom API base URL
php artisan module:generate --postman-prefix=api/v2                   # Custom API prefix
```

### 3. Generate Individual Components

You can also generate specific components separately:

#### Generate Postman Collection Only
```bash
php artisan postman:generate
php artisan postman:generate --file=custom/models.yaml
php artisan postman:generate --base-url=https://api.myapp.com --prefix=api/v1
```

#### Generate DB Diagram Only
```bash
php artisan dbdiagram:generate
php artisan dbdiagram:generate --file=custom/models.yaml --output=custom/database.dbml
```

---

## 🧪 What Gets Generated

For each model defined in your YAML file, the package will generate:

### Core Laravel Components
- ✅ **Eloquent Model** → `app/Models/`
- ✅ **Migration** → `database/migrations/`
- ✅ **API Controller** → `app/Http/Controllers/`
- ✅ **Service Class** → `app/Services/`
- ✅ **Form Request** → `app/Http/Requests/`
- ✅ **API Resource** → `app/Http/Resources/`
- ✅ **Resource Collection** → `app/Http/Resources/`
- ✅ **Route Registration** → `routes/api.php`

### 🆕 Documentation & Testing
- ✅ **Postman Collection** → `module/postman_collection.json`
- ✅ **DB Diagram** → `module/dbdiagram.dbml`

---

## 📋 Postman Collection Features

The generated Postman collection includes:

- **Complete CRUD operations** for each model
- **Proper HTTP methods** (GET, POST, PUT, DELETE)
- **Request examples** with sample data
- **Environment variables** for base URL and API prefix
- **Organized folder structure** by model
- **Authentication placeholders**

**Sample generated endpoints:**
```
GET    {{base_url}}/{{api_prefix}}/users        # List all users
POST   {{base_url}}/{{api_prefix}}/users        # Create user
GET    {{base_url}}/{{api_prefix}}/users/{id}   # Show user
PUT    {{base_url}}/{{api_prefix}}/users/{id}   # Update user
DELETE {{base_url}}/{{api_prefix}}/users/{id}   # Delete user
```

## 🗄️ Database Diagram Features

The generated DB diagram (.dbml) includes:

- **Complete table definitions** with all columns
- **Relationship mappings** (foreign keys, indexes)
- **Data types and constraints**
- **Compatible with [dbdiagram.io](https://dbdiagram.io)** for visualization
- **Exportable to various formats** (PNG, PDF, SQL)

**Usage with dbdiagram.io:**
1. Copy the content from `module/dbdiagram.dbml`
2. Visit [dbdiagram.io](https://dbdiagram.io)
3. Paste the content to visualize your database schema
4. Export as needed (PNG, PDF, SQL)

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
    // Postman collection settings
    'postman' => [
        'default_base_url' => '{{base-url}}',
        'default_prefix' => 'api/v1',
        'output_path' => 'module/postman_collection.json',
    ],
    // DB diagram settings
    'dbdiagram' => [
        'output_path' => 'module/dbdiagram.dbml',
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

### Selective Generation
Control what gets generated for each model:
```yaml
User:
  fields:
    name: string
    email: string
  generate:
    controller: true
    service: false
    request: true
    resource: true
    collection: false
```

---

## 🚀 Complete Workflow Example

Here's a complete workflow from YAML to production-ready API:

```bash
# 1. Create your YAML schema
vim module/models.yaml

# 2. Generate everything at once
php artisan module:generate --force

# 3. Run migrations
php artisan migrate

# 4. Import Postman collection for testing
# File: module/postman_collection.json

# 5. Visualize database schema
# Copy module/dbdiagram.dbml to dbdiagram.io

# 6. Start developing!
php artisan serve
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

- [x] ~~Postman collection generation~~
- [x] ~~Database diagram export~~
- [ ] Support for additional relationship types
- [ ] GUI for YAML configuration
- [ ] Integration with Laravel Sanctum
- [ ] Custom validation rule generation
- [ ] Support for nested resources
- [ ] OpenAPI/Swagger documentation generation
- [ ] Insomnia collection export
- [ ] GraphQL schema generation

---

## 📈 Recent Updates

### v1.0.10
- ✅ **NEW**: Postman collection generation
- ✅ **NEW**: Database diagram export (dbdiagram.io compatible)
- ✅ **NEW**: Selective component generation
- ✅ **IMPROVED**: Enhanced command options and flexibility
- ✅ **IMPROVED**: Better error handling and user feedback

---

*Happy coding! 🎉*
