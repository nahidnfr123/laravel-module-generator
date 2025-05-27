# Laravel Module Generator

A developer-friendly Laravel package to generate complete modules (Model, Migration, Controller, Service, Resource, Collection, Form Request, and Routes) from a single YAML configuration file. Supports custom stub overrides, allowing you to keep your codebase clean and consistent.

---

## ✨ Features

- Generate full Laravel modules from YAML
- Customizable stubs (optional override)
- Supports Models, Migrations, Controllers, Services, Requests, Resources, Collections, and Routes
- Smart fillable and relationship handling
- Designed for rapid development

---

## 🚀 Installation

```bash
composer require  nahid-ferdous/laravel-module-generator --dev
```


## 🚀 Publish Config & Stubs (Optional)

```bash
php artisan vendor:publish --tag=module-generator-config
php artisan vendor:publish --tag=module-generator-stubs
```
This will publish to:

Config: config/module-generator.php

Stubs: module/stub/