# Laravel Module Generator

A developer-friendly Laravel package to generate complete modules (Model, Migration, Controller, Service, Resource, Collection, Form Request, and Routes) from a single YAML configuration file. Now includes **Authentication & User Management**, **Postman collection generation**, and **DB diagram export** for streamlined API development and documentation.

---

## ✨ Features

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

---

## 🚀 Installation

Install the package via Composer:

```bash
composer require nahid-ferdous/laravel-module-generator --dev
```

### 📦 Service Provider

Generate required files and configurations:

```bash
php artisan module-generator:install
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

## 🔐 Authentication & User Management

### Generate Authentication System

Generate a complete authentication and user management system with a single command:

```bash
php artisan auth:generate
```

**Available Options:**

```bash
php artisan auth:generate --force              # Overwrite existing files without confirmation
php artisan auth:generate --skip-roles         # Skip roles and permissions setup
```

### What Gets Generated

#### Authentication Files
- ✅ **AuthController** → `app/Http/Controllers/AuthController.php`
- ✅ **AuthService** → `app/Services/AuthService.php`
- ✅ **Login Request** → `app/Http/Requests/Auth/LoginRequest.php`
- ✅ **Register Request** → `app/Http/Requests/Auth/RegisterRequest.php`
- ✅ **Forgot Password Request** → `app/Http/Requests/Auth/ForgotPasswordRequest.php`
- ✅ **Reset Password Request** → `app/Http/Requests/Auth/ResetPasswordRequest.php`
- ✅ **Auth Routes** → `routes/auth.php`

#### User Management Files
- ✅ **UserController** → `app/Http/Controllers/UserController.php`
- ✅ **UserService** → `app/Services/UserService.php`
- ✅ **Store User Request** → `app/Http/Requests/User/StoreUserRequest.php`
- ✅ **Update User Request** → `app/Http/Requests/User/UpdateUserRequest.php`
- ✅ **User Resource** → `app/Http/Resources/UserResource.php`
- ✅ **User Collection** → `app/Http/Resources/UserCollection.php`
- ✅ **User Routes** → `routes/user.php`

#### Roles & Permissions Files (Optional)
- ✅ **RoleController** → `app/Http/Controllers/RoleController.php`
- ✅ **PermissionController** → `app/Http/Controllers/PermissionController.php`
- ✅ **RoleService** → `app/Services/RoleService.php`
- ✅ **PermissionService** → `app/Services/PermissionService.php`
- ✅ **Role Requests** → `app/Http/Requests/Role/`
- ✅ **Permission Requests** → `app/Http/Requests/Permission/`
- ✅ **Role Resources** → `app/Http/Resources/`
- ✅ **Permission Resources** → `app/Http/Resources/`
- ✅ **Role Routes** → `routes/role.php`
- ✅ **Permission Routes** → `routes/permission.php`
- ✅ **Spatie Package** → Automatically installed

### Authentication Endpoints

The generated authentication system includes:

```
POST   /api/register          # Register new user
POST   /api/login             # Login user
POST   /api/logout            # Logout user
POST   /api/forgot-password   # Send password reset link
POST   /api/reset-password    # Reset password
GET    /api/me                # Get authenticated user
PUT    /api/profile           # Update user profile
```

### User Management Endpoints

```
GET    /api/users             # List all users
POST   /api/users             # Create new user
GET    /api/users/{id}        # Get user details
PUT    /api/users/{id}        # Update user
DELETE /api/users/{id}        # Delete user
```

### Roles & Permissions Endpoints (Optional)

```
GET    /api/roles             # List all roles
POST   /api/roles             # Create role
GET    /api/roles/{id}        # Get role details
PUT    /api/roles/{id}        # Update role
DELETE /api/roles/{id}        # Delete role
POST   /api/roles/{id}/permissions  # Assign permissions to role

GET    /api/permissions       # List all permissions
POST   /api/permissions       # Create permission
GET    /api/permissions/{id}  # Get permission details
PUT    /api/permissions/{id}  # Update permission
DELETE /api/permissions/{id}  # Delete permission
```

### Setup Instructions

After generating the authentication system, follow these steps:

1. **Register Routes** in `routes/api.php`:

```php
// Authentication routes (public)
Route::middleware('api')->group(base_path('routes/auth.php'));

// User management routes (protected)
Route::middleware(['api', 'auth:sanctum'])->group(base_path('routes/user.php'));

// Roles & Permissions routes (protected) - if generated
Route::middleware(['api', 'auth:sanctum'])->group(base_path('routes/role.php'));
Route::middleware(['api', 'auth:sanctum'])->group(base_path('routes/permission.php'));
```

2. **Install Laravel Sanctum** (if not already installed):

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

3. **Update User Model** (for roles & permissions):

```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;
    
    // ... rest of your model
}
```

4. **Run Migrations**:

```bash
php artisan migrate
```

5. **Configure Mail** in `.env` for password reset:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourapp.com
MAIL_FROM_NAME="${APP_NAME}"
```

### File Replacement Handling

When generating authentication files, if a file already exists:
- You'll be prompted to confirm replacement
- Use `--force` flag to automatically overwrite all files
- Skip files individually when prompted

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
    model: false
    migration: false
    controller: true
    service: true
    request: true
    resource: true
    collection: true
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
  requestParent: Unit
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
php artisan module:generate --skip-backup                             # Skip Code Backup generation
php artisan module:generate --postman-base-url=https://api.myapp.com  # Custom API base URL
php artisan module:generate --postman-prefix=api/v2                   # Custom API prefix
```

### 3. Generate Individual Components

You can also generate specific components separately:

#### Generate Authentication System

```bash
php artisan auth:generate
php artisan auth:generate --force
php artisan auth:generate --skip-roles
```

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

#### Backup Existing Files While Generating

```bash
# Generate with backup (default)
php artisan module:generate --file=models.yaml

# Generate without backup
php artisan module:generate --file=models.yaml --skip-backup

# List available backups
php artisan module:rollback --list

# Rollback to latest backup  
php artisan module:rollback

# Rollback to specific backup
php artisan module:rollback --backup=2025-01-15_14-30-22

# Clean up old backups
php artisan module:rollback --cleanup
```

---

## 🚀 Complete Workflow Example

Here's a complete workflow from YAML to production-ready API with authentication:

```bash
# 1. Generate authentication system
php artisan auth:generate

# 2. Create your YAML schema
vim module/models.yaml

# 3. Generate everything at once
php artisan module:generate --force

# 4. Run migrations
php artisan migrate

# 5. Import Postman collection for testing
# File: module/postman_collection.json

# 6. Visualize database schema
# Copy module/dbdiagram.dbml to dbdiagram.io

# 7. Start developing!
php artisan serve
```

---

## 🚀 Roadmap

- [x] ~~Postman collection generation~~
- [x] ~~Database diagram export~~
- [x] ~~Authentication & User Management~~
- [x] ~~Roles & Permissions (Spatie Integration)~~
- [ ] Support for additional relationship types
- [ ] GUI for YAML configuration
- [ ] Custom validation rule generation
- [ ] Support for nested resources
- [ ] OpenAPI/Swagger documentation generation
- [ ] Insomnia collection export
- [ ] GraphQL schema generation
- [ ] Two-Factor Authentication (2FA)
- [ ] Social Authentication (OAuth)

---

## 📈 Recent Updates

### v1.1.0

- ✅ **NEW**: Authentication system generation
- ✅ **NEW**: User management system
- ✅ **NEW**: Roles & Permissions with Spatie integration
- ✅ **NEW**: File replacement confirmation
- ✅ **IMPROVED**: Better command structure and options

### v1.0.10

- ✅ **NEW**: Postman collection generation
- ✅ **NEW**: Database diagram export (dbdiagram.io compatible)
- ✅ **NEW**: Selective component generation
- ✅ **IMPROVED**: Enhanced command options and flexibility
- ✅ **IMPROVED**: Better error handling and user feedback

---

*Happy coding! 🎉*