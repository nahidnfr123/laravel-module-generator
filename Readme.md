# Laravel Module Generator

## 📖 Introduction

A developer-friendly Laravel package to generate complete modules (Model, Migration, Controller, Service, Resource, Collection, Form Request, and Routes) from a single YAML configuration file. Now includes **Authentication (Sanctum & Passport) & User Management**, **Postman collection generation**, and **DB diagram export** for streamlined API development and documentation.

### ✨ Features

- Generate full Laravel modules from YAML configuration
- **🆕 Built-in Authentication & User Management**
- **🆕 Roles & Permissions Management (Spatie Integration)**
- Customizable stub support (with fallback to internal defaults)
- **Postman collection generation** for instant API testing
- **Database diagram export** compatible with [dbdiagram.io](https://dbdiagram.io)
- Generates:
    - Models with relationships
    - Database migrations
    - API Controllers
    - Service classes
    - Form Request validation
    - API Resources & Collections
    - Route entries
    - **Authentication System**
    - **User Management System**
    - **Roles & Permissions System**
    - **Postman collection files**
    - **DB diagram files (.dbml)**
- Smart fillable and relationship handling
- Designed for rapid development and prototyping




## 🚀 Installation

Install the package via Composer:

```bash
composer require nahid-ferdous/laravel-module-generator --dev
```

### Setup

Generate required files and configurations:

```bash
php artisan module-generator:install
```

Or force install if you've already executed the command:

```bash
php artisan module-generator:install --force
```

### What Gets Generated

- **Useful Traits** → `app/Traits/`
- **Global Exception Handler** → `app/Exceptions/ExceptionHandler.php`
- **Helper Functions** → `app/Helpers/`
- **Middleware** (CORS & Auth) → `app/Http/Middleware/`
- **Configuration** → `module/modules.yaml`

### Optional: Publish Config & Stubs

Customize the package defaults by publishing configuration and stubs:

```bash
# Publish configuration
php artisan vendor:publish --tag=module-generator-config

# Publish stubs for customization
php artisan vendor:publish --tag=module-generator-stubs
```

This creates:
- `config/module-generator.php`
- `module/stub/` directory




## 🔐 Authentication & User Management

> **Prerequisites:** Configure your `.env` DB connection before generating the authentication module.

### Generate Auth System

Create a complete authentication system with a single command:

```bash
php artisan auth:generate
```

**Available Options:**

```bash
php artisan auth:generate --force                      # Overwrite existing files
php artisan auth:generate --skip-roles                 # Skip roles & permissions
php artisan auth:generate --skip-email-verification    # Skip email verification
php artisan auth:generate --with-social-login          # Include social auth
```

### What Gets Generated

**Authentication Files:**
- AuthController & AuthService
- Login, Register, Password Reset Requests
- Email Verification (optional)
- Auth Routes

**User Management Files:**
- UserController & UserService
- User Requests, Resources & Collections

**Roles & Permissions (optional):**
- RoleController & PermissionController
- Role & Permission Services
- Spatie Laravel Permissions (auto-installed)
- Permission & User Seeders

**Social Authentication (optional):**
- SocialAuthController
- Laravel Socialite (auto-installed)

### API Endpoints

**Authentication:**
```
POST   /api/register                              # Register new user
POST   /api/login                                 # Login user
POST   /api/logout                                # Logout user
POST   /api/password/forgot                       # Send reset link
POST   /api/password/reset                        # Reset password
GET    /api/profile                               # Get user profile
PUT    /api/profile                               # Update profile
POST   /api/email/verify                          # Verify email (optional)
POST   /api/email/resend-verification-link        # Resend verification (optional)
```

**User Management:**
```
GET    /api/users             # List all users
POST   /api/users             # Create user
GET    /api/users/{id}        # Get user details
PUT    /api/users/{id}        # Update user
DELETE /api/users/{id}        # Delete user
```

**Roles & Permissions:**
```
GET    /api/roles             # List roles
POST   /api/roles             # Create role
GET    /api/roles/{id}        # Get role
PUT    /api/roles/{id}        # Update role
DELETE /api/roles/{id}        # Delete role
GET    /api/permissions       # List permissions
POST   /api/permissions       # Create permission
GET    /api/permissions/{id}  # Get permission
PUT    /api/permissions/{id}  # Update permission
DELETE /api/permissions/{id}  # Delete permission
POST   /api/assign-permission-to-role   # Assign permissions to role
POST   /api/assign-permission-to-user   # Assign permissions to user
```

**Social Authentication:**
```
GET    /api/auth/social/{provider}           # Redirect to provider
GET    /api/auth/social/{provider}/callback  # Handle callback
```

### Setup Instructions

#### 1. Choose Authentication Guard (Sanctum or Passport)

**Sanctum (Recommended for Single-Page Apps):**
```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

Update `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->statefulApi();
})
```

**Passport (For Third-Party Clients):**
```bash
composer require laravel/passport
php artisan vendor:publish --tag=passport-migrations
php artisan vendor:publish --tag=passport-config
php artisan migrate
php artisan passport:client --personal
```

#### 2. Update User Model

**For Sanctum:**
```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
}
```

**For Passport:**
```php
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
}
```

**For Roles & Permissions:**
```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles, HasFactory, Notifiable;
}
```

#### 3. Update Configuration Files

**`.env`:**
```env
AUTH_GUARD='api'
FRONTEND_URL=http://localhost:5173
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourapp.com
MAIL_FROM_NAME="${APP_NAME}"
```

**`config/auth.php`:**
```php
'guards' => [
    'api' => [
        'driver' => 'sanctum',  // or 'passport'
        'provider' => 'users',
        'hash' => false,
    ],
],
```

#### 4. Register Routes

Update `routes/api.php`:
```php
require __DIR__.'/api/auth.php';              // Authentication routes
require __DIR__.'/api/access-control.php';    // Roles & permissions (if generated)
require __DIR__.'/api/social-auth.php';       // Social auth (if generated)
```

#### 5. Configure Social Providers (Optional)

Update `config/services.php`:
```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_CALLBACK_URL'),
],
'facebook' => [
    'client_id' => env('FACEBOOK_CLIENT_ID'),
    'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
    'redirect' => env('FACEBOOK_CALLBACK_URL'),
],
'github' => [
    'client_id' => env('GITHUB_CLIENT_ID'),
    'client_secret' => env('GITHUB_CLIENT_SECRET'),
    'redirect' => env('GITHUB_CALLBACK_URL'),
],
```

#### 6. Seed Initial Data

`database/seeders/DatabaseSeeder.php`:
```php
public function run()
{
    $this->call([
        PermissionSeeder::class,
        UserTableSeeder::class,
    ]);
}
```

Run:
```bash
php artisan migrate
php artisan db:seed
```



## 🛠️ Module Generation from YAML

### Quick Start

Generate a complete module set (Model, Migration, Controller, Service, Resource, Collection, Form Request, Routes) from a single YAML file.

```bash
php artisan module:generate
```

**Default YAML location:** `module/models.yaml`

**Use custom file:**
```bash
php artisan module:generate --file=custom/path/models.yaml
```

### YAML Configuration Guide

#### Minimal Example

```yaml
Product:
  fields:
    name: string:unique
    code: string:unique
    description: text:nullable
    is_active: boolean:default true
  relations:
    belongsTo: User:creator
```

#### Complete Example

```yaml
User:
  generate_only: seeder
  fields:
    name: string
    email: string:unique
    email_verified_at: dateTime:nullable
    password: string
    avatar: image:nullable
    status: boolean:default true
    last_login_at: timestamp:nullable

Category:
  generate_except: seeder
  fields:
    name: string:unique
    slug: string:unique
    description: text:nullable
    image: image:nullable
    parent_id: foreignId:categories:nullable
    is_active: boolean:default true
    display_order: integer:default 0
    seo_title: string:nullable
    seo_description: string:nullable
    seo_keywords: string:nullable
    created_by: foreignId:users:nullable
    updated_by: foreignId:users:nullable
  relations:
    belongsTo: Category:parent, User:creator, User:updater
    hasMany: Category:children, Product:products
  nested_requests: children

Product:
  generate: all
  fields:
    vendor_id: foreignId:vendors:nullable
    category_id: foreignId:categories
    brand_id: foreignId:brands:nullable
    name: string
    slug: string:unique
    sku: string:unique
    description: text:nullable
    short_description: string:nullable
    price: double:default 0
    cost_price: double:default 0
    compare_price: double:default 0
    quantity: integer:default 0
    is_active: boolean:default true
    is_featured: boolean:default false
    rating: double:default 0
    total_reviews: integer:default 0
    weight: double:nullable
    dimensions: string:nullable
    seo_title: string:nullable
    seo_description: string:nullable
    seo_keywords: string:nullable
    meta_data: json:nullable
    created_by: foreignId:users:nullable
    updated_by: foreignId:users:nullable
    deleted_at: dateTime:nullable
  relations:
    belongsTo: Vendor, Category, Brand, User:creator, User:updater
    hasMany: Review, ProductVariant:variants, ProductImage:images
    belongsToMany: ProductAttribute
  nested_requests: variants, images

ProductImage:
  generate_only: model, migration, seeder, service
  fields:
    product_id: foreignId:products
    image_url: image
    alt_text: string:nullable
    display_order: integer:default 0
    is_thumbnail: boolean:default false
    created_by: foreignId:users:nullable
    updated_by: foreignId:users:nullable
  relations:
    belongsTo: Product, User:creator, User:updater

ProductVariant:
  generate: all
  fields:
    product_id: foreignId:products
    sku: string:unique
    name: string
    price: double:nullable
    cost_price: double:nullable
    quantity: integer:default 0
    attributes_data: json:nullable
    image_id: foreignId:product_images:nullable
    is_active: boolean:default true
    created_by: foreignId:users:nullable
    updated_by: foreignId:users:nullable
  relations:
    belongsTo: Product, ProductImage:image, User:creator, User:updater

ProductAttributeValue:
  generate: all
  fields:
    attribute_id: foreignId:product_attributes
    value: string
    slug: string
    display_order: integer:default 0
    created_by: foreignId:users:nullable
    updated_by: foreignId:users:nullable
  relations:
    belongsTo: ProductAttribute:attribute, User:creator, User:updater
  unique:
    - [product_attribute_id, value]
```

### Generation Commands

#### Main Command: `module:generate`

Generate complete API modules from YAML schema.

**Signature:**
```bash
php artisan module:generate
    {--file= : Path to YAML file (default: module/models.yaml)}
    {--force : Overwrite files without prompting}
    {--skip-backup : Skip creating backups}
    {--skip-postman : Skip Postman collection}
    {--skip-dbdiagram : Skip DB diagram}
    {--postman-base-url= : Base URL for Postman}
    {--postman-prefix= : API prefix for Postman}
```

**Examples:**
```bash
# Generate with defaults
php artisan module:generate

# Force overwrite existing files
php artisan module:generate --force

# Custom file and Postman settings
php artisan module:generate \
  --file=schemas/products.yaml \
  --postman-base-url=https://api.myapp.com \
  --postman-prefix=api/v2

# Skip Postman and DBML generation
php artisan module:generate --skip-postman --skip-dbdiagram
```

#### Database Diagram: `dbdiagram:generate`

Export your YAML schema as DBML for visualization.

**Signature:**
```bash
php artisan dbdiagram:generate
    {--file= : Path to YAML file}
    {--output= : Output DBML file path}
```

**Examples:**
```bash
# Generate with defaults
php artisan dbdiagram:generate

# Custom output location
php artisan dbdiagram:generate --output=docs/schema.dbml
```

Use [dbdiagram.io](https://dbdiagram.io) to visualize and export diagrams.

#### Postman Collection: `postman:generate`

Generate a Postman collection for API testing.

**Signature:**
```bash
php artisan postman:generate
    {--file= : Path to YAML file}
    {--base-url= : Base URL for API}
    {--prefix= : API prefix}
    {--output= : Output file path}
```

**Examples:**
```bash
# Generate with defaults
php artisan postman:generate

# Custom base URL and prefix
php artisan postman:generate \
  --base-url=https://api.myapp.com \
  --prefix=api/v2
```

#### DBML to YAML: `dbdiagram:import`

Convert existing DBML files to YAML format.

**Signature:**
```bash
php artisan dbdiagram:import
    {--file= : Input DBML file}
    {--output= : Output YAML file}
```

**Workflow:**
1. Design schema at [dbdiagram.io](https://dbdiagram.io)
2. Export DBML
3. Save to `.dbml` file
4. Run import command
5. Review and use generated YAML

#### Rollback: `module:rollback`

Restore to a previous generation state using automatic backups.

**Signature:**
```bash
php artisan module:rollback
    {--backup= : Specific backup timestamp}
    {--list : List available backups}
    {--cleanup : Remove old backups}
```

**Examples:**
```bash
# List available backups
php artisan module:rollback --list

# Rollback to most recent backup
php artisan module:rollback

# Rollback to specific backup
php artisan module:rollback --backup=2024-01-15_143022

# Clean up old backups
php artisan module:rollback --cleanup
```

### YAML Field Types & Modifiers

**Supported Types:**
- String: `string`, `text`, `longText`
- Numeric: `integer`, `bigInteger`, `double`, `float`, `decimal`
- Boolean: `boolean`
- Date/Time: `date`, `dateTime`, `timestamp`, `time`
- Files: `image`, `file` (auto-handles uploads)
- JSON: `json`
- Enum: `enum`
- Foreign Keys: `foreignId:table_name`
- Soft Deletes: `deleted_at: dateTime:nullable`

**Common Modifiers:**
- `nullable` — Column can be null
- `unique` — Add unique constraint
- `default <value>` — Set default value

**Field Examples:**
```yaml
fields:
  name: string
  email: string:unique
  description: text:nullable
  avatar: image:nullable
  is_active: boolean:default true
  price: double:default 0
  user_id: foreignId:users
  parent_id: foreignId:categories:nullable
  published_at: dateTime:nullable
  deleted_at: dateTime:nullable
  meta_data: json:nullable
  slug: string:unique:nullable
```

### Relationships

**Format:** `relationType: Model:functionName, Model:functionName`

**Supported Types:**
- `belongsTo` — Many-to-one
- `hasMany` — One-to-many
- `hasOne` — One-to-one
- `belongsToMany` — Many-to-many

**Examples:**
```yaml
relations:
  belongsTo: User:creator, User:updater, Category:parent
  hasMany: Category:children, Product:products
  hasOne: Profile
  belongsToMany: Tag, Category
```

**Special Mapping:**
- `creator` → `created_by` field
- `updater` → `updated_by` field
- `parent` → `parent_id` field

### Generation Control




**Control Options:**
- `generate: all` — Generate all components
- `generate_only: model, migration` — Only these components
- `generate_except: seeder` — All except these
- `nested_requests: variants, images` — Include in Postman bodies

**Valid Components:**
`model`, `migration`, `controller`, `service`, `request`, `resource`, `collection`, `seeder`

**Compound Unique Constraints:**
```yaml
ProductAttribute:
  fields:
    product_id: foreignId:products
    attribute_id: foreignId:attributes
  unique:
    - [product_id, attribute_id]
```

### Output Locations

- Models: `app/Models/`
- Migrations: `database/migrations/`
- Controllers: `app/Http/Controllers/`
- Services: `app/Services/`
- Requests: `app/Http/Requests/`
- Resources: `app/Http/Resources/`
- Collections: `app/Http/Resources/`
- Seeders: `database/seeders/`
- Routes: Appended to `routes/api.php`
- Postman: `module/postman_collection.json`
- DBML: `module/dbdiagram.dbml`
- Backups: `module/backups/{timestamp}/`

### Troubleshooting

**Auth Module Conflicts:**
If you ran `auth:generate` previously, set `User.generate_only: seeder` in YAML to avoid overwriting custom auth code.

**Migration Issues:**
Use new migrations to alter existing tables rather than editing previously-applied migrations.

**Foreign Key Naming:**
Ensure `foreignId:table` values match your actual table names.

**File Uploads:**
Models with `image` or `file` fields automatically generate form-data Postman requests.

**Version Control:**
Keep your YAML schema in Git and use branches before running `--force` to make rollbacks easier.

## 🚀 Complete Workflow

From installation to production-ready API:

### 1. Generate Authentication (Optional)

```bash
php artisan auth:generate
```

### 2. Create YAML Schema

Create or edit `module/models.yaml` with your models and fields.

### 3. Review Generated Files

```bash
php artisan module:generate
```

Review output before running `--force`.

### 4. Apply Migrations

```bash
php artisan migrate
```

### 5. Test with Postman

Import `module/postman_collection.json` into Postman and test endpoints.

### 6. Visualize Database

Copy DBML output to [dbdiagram.io](https://dbdiagram.io) for visualization.

### 7. Local Testing

```bash
php artisan serve
```

### 8. Deploy

Commit changes and deploy:

```bash
git add .
git commit -m "Generate API modules from YAML"
git push origin feature/api-modules
```

---

---

Following this workflow will help you safely move from YAML schemas to fully-functional API endpoints.

## ✨ Features Summary

- ✅ Generate complete Laravel modules from YAML
- ✅ Authentication & User Management (Sanctum & Passport)
- ✅ Roles & Permissions (Spatie Integration)
- ✅ Social Authentication (OAuth)
- ✅ Postman collection generation
- ✅ Database diagram export (DBML)
- ✅ Automatic backups & rollback support
- ✅ Customizable stubs
- ✅ Smart fillable and relationship handling
- ✅ File upload handling for image/file fields

## 🚀 Roadmap

- [ ] GUI for YAML configuration
- [ ] OpenAPI/Swagger documentation generation
- [ ] Insomnia collection export
- [ ] GraphQL schema generation
- [ ] Two-Factor Authentication (2FA)
- [ ] Additional relationship types

## 📝 Recent Updates

### v1.3.00
- ✅ IMPROVED: YAML file structure and options
- ✅ NEW: Database to Models YAML conversion

### v1.2.50
- ✅ NEW: Passport support added
- ✅ IMPROVED: Better error handling and user feedback

### v1.2.0
- ✅ NEW: Authentication system generation
- ✅ NEW: User management system
- ✅ NEW: Roles & Permissions with Spatie integration

### v1.0.10
- ✅ NEW: Postman collection generation
- ✅ NEW: Database diagram export
- ✅ NEW: Selective component generation

Happy generating! 🎉
