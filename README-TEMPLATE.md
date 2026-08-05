# Laravel Backend Template 🚀

Plantilla base de Laravel 10 con arquitectura limpia, JWT, middlewares y estructura organizada lista para producción.

## 📋 Características

- ✅ Laravel 10.10 con PHP 8.1+
- ✅ Autenticación JWT (Tymon JWT-Auth)
- ✅ Laravel Sanctum para API tokens
- ✅ Arquitectura limpia y escalable
- ✅ Middlewares personalizados
- ✅ Sistema de permisos
- ✅ Exportación CSV
- ✅ Request validation
- ✅ CORS configurado
- ✅ Tests configurados
- ✅ Vite para assets frontend

## 🚀 Instalación Rápida

### 1. Copiar la plantilla
```bash
cp -r laravel-backend-template mi-nuevo-proyecto
cd mi-nuevo-proyecto
```

### 2. Actualizar composer.json
Edita composer.json y cambia el nombre del proyecto

### 3. Instalar dependencias
```bash
composer install
npm install
```

### 4. Configurar entorno
```bash
cp .env.example .env
# Edita .env con tus configuraciones
```

### 5. Generar claves
```bash
php artisan key:generate
php artisan jwt:secret
```

### 6. Configurar base de datos
Edita .env y configura:
- DB_DATABASE
- DB_USERNAME  
- DB_PASSWORD

### 7. Ejecutar migraciones
```bash
php artisan migrate
```

### 8. Iniciar servidor
```bash
php artisan serve
npm run dev
```

## 📡 API Endpoints

### Autenticación
- POST /api/login
- POST /api/register
- POST /api/logout (requiere JWT)
- POST /api/refresh (requiere JWT)
- GET /api/me (requiere JWT)

## 🔑 Middlewares Disponibles

- jwt.verify - Verifica token JWT
- permisos.verify - Verifica permisos
- auth - Autenticación estándar

## 📄 Licencia

MIT License
