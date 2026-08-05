# Backend - Stockana

Backend desarrollado con Laravel 10 que incluye sistema completo de gestión de stock (productos, compras, ventas) con autenticación JWT, usuarios, roles y permisos.

## Requisitos

- PHP >= 8.1
- Composer
- MySQL >= 5.7
- Node.js >= 16

---

## Instalación

```bash
# 1. Instalar dependencias
composer install

# 2. Copiar configuración
cp .env.example .env

# 3. Configurar base de datos en .env
# DB_DATABASE=tu_base_de_datos
# DB_USERNAME=root
# DB_PASSWORD=tu_contraseña

# 4. Generar claves
php artisan key:generate
php artisan jwt:secret

# 5. Ejecutar migraciones y seeders
php artisan migrate
php artisan db:seed

# 6. Iniciar servidor
php artisan serve
```

**Usuario admin por defecto:**
- Email: `admin@admin.com`
- Password: `admin123`

---

## Estructura de Base de Datos

### Diagrama de Relaciones Completo

**SISTEMA DE AUTENTICACIÓN Y PERMISOS**

```
┌─────────────────┐       ┌─────────────────┐
│  tipo_usuarios  │       │      roles      │
├─────────────────┤       ├─────────────────┤
│ id              │       │ id              │
│ codigo          │       │ codigo          │
│ detalle         │       │ nombre          │
└────────┬────────┘       │ descripcion     │
         │                └────────┬────────┘
         │                         │
         │                         ▼
         │                ┌─────────────────┐
         │                │   rol_permisos  │
         │                ├─────────────────┤
         │                │ id_rol (FK)     │
         │                │ id_permiso (FK) │
         │                └────────┬────────┘
         │                         │
         │                         ▼
         │                ┌─────────────────┐
         │                │    permisos     │
         │                ├─────────────────┤
         │                │ id              │
         │                │ codigo          │
         │                │ nombre          │
         │                │ grupo           │
         │                └────────┬────────┘
         │                         │
         ▼                         │
┌─────────────────┐                │
│      users      │◄───────────────┘
├─────────────────┤
│ nro_usu (PK)    │
│ des_usu         │
│ email           │
│ password        │
│ id_tipo_usuario │
│ id_rol          │
│ is_admin        │
│ deleted_at      │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│permisos_usuarios│
├─────────────────┤
│ id (PK)         │
│ id_usuario (FK) │
│ id_permiso (FK) │
└─────────────────┘
```

**SISTEMA DE GESTIÓN DE STOCK**

```
                     ┌──────────────┐
                     │  categorias  │
                     ├──────────────┤
                     │ id      (PK) │
                     │ categoria    │
                     └──────┬───────┘
                            │
                            ▼
                     ┌──────────────┐
                     │  productos   │
                     ├──────────────┤
                     │ id      (PK) │
                     │ producto     │
                     │ precio       │
                     │ estado       │
                     │ id_categoria │
                     └──────┬───────┘
                            │
               ┌────────────┴────────────┐
               │                         │
               ▼                         ▼
      ┌─────────────────┐       ┌─────────────────┐
      │ lineas_compras  │       │  lineas_ventas  │
      ├─────────────────┤       ├─────────────────┤
      │ id_linea   (PK) │       │ id_linea   (PK) │
      │ id_compra  (FK) │       │ id_venta   (FK) │
      │ id_producto(FK) │       │ id_producto(FK) │
      │ precio_compra   │       │ precio_venta    │
      │ cantidad        │       │ cantidad        │
      └────────▲────────┘       └────────▲────────┘
               │                         │
               │                         │
      ┌────────┴────────┐       ┌────────┴────────┐
      │     compras     │       │      ventas     │
      ├─────────────────┤       ├─────────────────┤
      │ id          (PK)│       │ id          (PK)│
      │ id_proveedor(FK)│       │ id_cliente  (FK)│
      │ id_usuario  (FK)│───┐   │ id_usuario  (FK)│───┐
      │ estado          │   │   │ estado          │   │
      │ fecha           │   │   │ fecha           │   │
      │ monto_total     │   │   │ monto_total     │   │
      │ cuit            │   │   │ cuit            │   │
      └────────▲────────┘   │   └─────────────────┘   │
               │            │                         │
               │            └─────────┬───────────────┘
      ┌────────┴────────┐             │
      │   proveedores   │             │  (FK a users.nro_usu)
      ├─────────────────┤             │
      │ id          (PK)│             ▼
      │ cuit            │     ┌─────────────────┐
      │ persona         │     │      users      │
      │ direccion       │     ├─────────────────┤
      │ telefono        │     │ nro_usu (PK)    │
      │ estado          │     │ des_usu         │
      │ email           │     │ email           │
      └─────────────────┘     │ ...             │
                              └─────────────────┘
               ┌─────────────────┐
               │     clientes    │
               ├─────────────────┤
               │ id          (PK)│
               │ cuit            │
               │ persona         │
               │ direccion       │
               │ telefono        │
               │ estado          │
               │ email           │
               └─────────────────┘
```

**Relaciones principales:**
- **users ↔ roles ↔ permisos**: Sistema de autenticación y autorización
- **users → compras/ventas**: Usuario que registró la transacción (campo id_usuario)
- **proveedores → compras**: Proveedor de la compra
- **clientes → ventas**: Cliente de la venta
- **productos ↔ categorías**: Clasificación de productos
- **productos ↔ lineas_compras/ventas**: Detalle de transacciones

---

## Tablas Explicadas

### Sistema de Autenticación y Permisos

#### 1. `users` - Usuarios del Sistema

Almacena todos los usuarios que pueden acceder al sistema.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `nro_usu` | bigint (PK) | ID único del usuario |
| `des_usu` | string | Nombre completo del usuario |
| `email` | string (unique) | Email para login |
| `password` | string | Contraseña encriptada |
| `admin` | boolean | Flag legacy de admin |
| `id_tipo_usuario` | FK nullable | Tipo de usuario (para categorizar) |
| `id_rol` | FK nullable | Rol asignado (define permisos base) |
| `is_admin` | boolean | Si es true, tiene TODOS los permisos |
| `deleted_at` | timestamp | Soft delete (no se borra, se marca) |

**Importante:** Si `is_admin = true`, el usuario bypasea TODAS las verificaciones de permisos.

---

#### 2. `tipo_usuarios` - Categorías de Usuarios

Sirve para categorizar usuarios (ej: "Empleado", "Cliente", "Abogado").

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | bigint (PK) | ID único |
| `codigo` | string (unique) | Código corto (ej: "ADMIN", "USER") |
| `detalle` | string | Descripción del tipo |

**Uso:** Es opcional. Sirve para filtrar o agrupar usuarios por categoría, independiente de sus permisos.

**Ejemplo:**
```
| codigo | detalle                          |
|--------|----------------------------------|
| ADMIN  | Usuario administrador del sistema |
| USER   | Usuario estándar del sistema      |
```

---

#### 3. `roles` - Roles del Sistema

Un rol es un "paquete de permisos" que se asigna a usuarios.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | bigint (PK) | ID único |
| `codigo` | string (unique) | Código identificador (ej: "admin", "gerente") |
| `nombre` | string | Nombre visible (ej: "Administrador") |
| `descripcion` | string nullable | Descripción del rol |

**Ejemplo:**
```
| codigo  | nombre        | descripcion                    |
|---------|---------------|--------------------------------|
| admin   | Administrador | Acceso total al sistema        |
| gerente | Gerente       | Gestión de usuarios            |
| usuario | Usuario       | Permisos limitados de lectura  |
```

---

#### 4. `permisos` - Permisos Disponibles

Define TODAS las acciones posibles en el sistema.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | bigint (PK) | ID único |
| `codigo` | string (unique) | Código único del permiso (ej: "create-usuarios") |
| `nombre` | string | Nombre descriptivo |
| `grupo` | string nullable | Agrupación para organizar en UI |

**Convención de nombres:** `accion-recurso`
- `list-usuarios` → Listar usuarios
- `view-usuarios` → Ver detalle de usuario
- `create-usuarios` → Crear usuario
- `update-usuarios` → Actualizar usuario
- `delete-usuarios` → Eliminar usuario

**Permisos incluidos por defecto:**

| Grupo | Permisos |
|-------|----------|
| usuarios | list, view, create, update, delete, restore |
| roles | list, view, create, update, delete |
| permisos | list, assign |
| configuracion | view, update |

---

#### 5. `rol_permisos` - Relación Rol ↔ Permisos

Tabla pivote que define qué permisos tiene cada rol.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id_rol` | FK | ID del rol |
| `id_permiso` | FK | ID del permiso |

**Ejemplo:** El rol "gerente" tiene estos permisos:
```
| id_rol | id_permiso |
|--------|------------|
| 2      | 1          | (list-usuarios)
| 2      | 2          | (view-usuarios)
| 2      | 3          | (create-usuarios)
| 2      | 4          | (update-usuarios)
```

---

#### 6. `permisos_usuarios` - Permisos Directos por Usuario

Permite asignar permisos ADICIONALES a un usuario específico, sin modificar su rol.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | bigint (PK) | ID único |
| `id_usuario` | FK | ID del usuario |
| `id_permiso` | FK | ID del permiso |

**¿Para qué sirve?**

Imaginá que tenés un usuario con rol "Usuario" (solo lectura), pero necesitás que SOLO ÉL pueda crear reportes. En lugar de crear un rol nuevo, le asignás el permiso `create-reportes` directamente.

---

## Sistema de Permisos - Cómo Funciona

### Flujo de Verificación

```
¿Usuario es admin (is_admin = true)?
    └── SÍ → ✅ ACCESO PERMITIDO (tiene todos los permisos)
    └── NO → Continuar verificación
              │
              ▼
Obtener permisos del ROL del usuario
              +
Obtener permisos DIRECTOS del usuario
              │
              ▼
¿Tiene el permiso requerido?
    └── SÍ → ✅ ACCESO PERMITIDO
    └── NO → ❌ ERROR 403 (Forbidden)
```

### Ejemplo Práctico

**Usuario Juan:**
- Rol: "gerente" (tiene: list-usuarios, view-usuarios, create-usuarios)
- Permisos directos: delete-reportes

**Permisos totales de Juan:** list-usuarios, view-usuarios, create-usuarios, delete-reportes

---

## Endpoints de la API

### Autenticación

| Método | Ruta | Descripción | Auth |
|--------|------|-------------|------|
| POST | `/api/login` | Iniciar sesión | No |
| POST | `/api/register` | Registrar usuario | No |
| POST | `/api/logout` | Cerrar sesión | JWT |
| POST | `/api/refresh` | Renovar token | JWT |
| GET | `/api/me` | Obtener usuario actual | JWT |

### Usuarios

| Método | Ruta | Descripción | Permiso |
|--------|------|-------------|---------|
| GET | `/api/users` | Listar usuarios | list-usuarios |
| GET | `/api/users/{id}` | Ver usuario | view-usuarios |
| POST | `/api/users` | Crear usuario | create-usuarios |
| PUT | `/api/users/{id}` | Actualizar usuario | update-usuarios |
| DELETE | `/api/users/{id}` | Eliminar usuario | delete-usuarios |
| PUT | `/api/users/{id}/restore` | Restaurar usuario | restore-usuarios |
| POST | `/api/users/cambiar-password` | Cambiar contraseña | (autenticado) |

### Roles

| Método | Ruta | Descripción | Permiso |
|--------|------|-------------|---------|
| GET | `/api/roles` | Listar roles | list-roles |
| GET | `/api/roles/{id}` | Ver rol con permisos | view-roles |
| POST | `/api/roles` | Crear rol | create-roles |
| PUT | `/api/roles/{id}` | Actualizar rol | update-roles |
| DELETE | `/api/roles/{id}` | Eliminar rol | delete-roles |

### Permisos

| Método | Ruta | Descripción | Permiso |
|--------|------|-------------|---------|
| GET | `/api/permisos` | Listar permisos | list-permisos |
| GET | `/api/permisos/agrupados` | Listar por grupo | list-permisos |
| GET | `/api/permisos/mis-permisos` | Mis permisos | (autenticado) |
| GET | `/api/permisos/usuario/{id}` | Permisos de usuario | list-usuarios |
| POST | `/api/permisos/usuario/{id}` | Asignar permisos | assign-permisos |

---

## Ejemplos de Uso

### Login

```bash
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email": "admin@admin.com", "password": "admin123"}'
```

**Respuesta:**
```json
{
  "success": true,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "token_type": "bearer",
  "expires_in": 3600,
  "user": {
    "nro_usu": 1,
    "des_usu": "Administrador",
    "email": "admin@admin.com",
    "is_admin": true,
    "rol": { ... },
    "tipoUsuario": { ... }
  }
}
```

### Usar Token en Peticiones

```bash
curl -X GET http://localhost:8000/api/users \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
```

### Crear Usuario

```bash
curl -X POST http://localhost:8000/api/users \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "des_usu": "Juan Pérez",
    "email": "juan@example.com",
    "password": "123456",
    "id_rol": 2,
    "permisos": [1, 2, 3]
  }'
```

### Crear Rol con Permisos

```bash
curl -X POST http://localhost:8000/api/roles \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "codigo": "operador",
    "nombre": "Operador",
    "descripcion": "Operador del sistema",
    "permisos": [1, 2, 7, 8]
  }'
```

---

## Agregar Nuevos Permisos

Para agregar permisos para un nuevo módulo (ej: "expedientes"):

### 1. Agregar al Seeder

Editar `database/seeders/PermisoSeeder.php`:

```php
$permisos = [
    // ... permisos existentes ...

    // Expedientes
    ['codigo' => 'list-expedientes', 'nombre' => 'Listar expedientes', 'grupo' => 'expedientes'],
    ['codigo' => 'view-expedientes', 'nombre' => 'Ver expediente', 'grupo' => 'expedientes'],
    ['codigo' => 'create-expedientes', 'nombre' => 'Crear expediente', 'grupo' => 'expedientes'],
    ['codigo' => 'update-expedientes', 'nombre' => 'Actualizar expediente', 'grupo' => 'expedientes'],
    ['codigo' => 'delete-expedientes', 'nombre' => 'Eliminar expediente', 'grupo' => 'expedientes'],
];
```

### 2. Ejecutar Seeder

```bash
php artisan db:seed --class=PermisoSeeder
```

### 3. Proteger Rutas

En `routes/api.php`:

```php
Route::prefix('expedientes')->group(function () {
    Route::get('/', [ExpedientesController::class, 'index'])
        ->middleware('permisos.verify:list-expedientes');
    Route::get('/{id}', [ExpedientesController::class, 'show'])
        ->middleware('permisos.verify:view-expedientes');
    Route::post('/', [ExpedientesController::class, 'store'])
        ->middleware('permisos.verify:create-expedientes');
    Route::put('/{id}', [ExpedientesController::class, 'update'])
        ->middleware('permisos.verify:update-expedientes');
    Route::delete('/{id}', [ExpedientesController::class, 'destroy'])
        ->middleware('permisos.verify:delete-expedientes');
});
```

---

## Estructura de Carpetas

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── AuthController.php       # Login, logout, register
│   │   ├── UsersController.php      # CRUD usuarios
│   │   ├── RolesController.php      # CRUD roles
│   │   └── PermisosController.php   # Gestión permisos
│   ├── Middleware/
│   │   ├── JwtMiddleware.php        # Valida token JWT
│   │   └── VerificarPermisos.php    # Verifica permisos
│   └── Requests/
│       ├── LoginRequest.php
│       ├── CreateUserRequest.php
│       ├── UpdateUserRequest.php
│       ├── CreateRolRequest.php
│       └── UpdateRolRequest.php
├── Models/
│   ├── User.php
│   ├── TipoUsuario.php
│   ├── Rol.php
│   ├── Permiso.php
│   ├── PermisoUsuario.php
│   └── RolPermiso.php
database/
├── migrations/
│   ├── 2014_10_12_000000_create_users_table.php
│   ├── 2024_01_01_000001_create_tipo_usuarios_table.php
│   ├── 2024_01_01_000003_create_roles_table.php
│   ├── 2024_01_01_000004_create_permisos_table.php
│   ├── 2024_01_01_000005_create_rol_permisos_table.php
│   ├── 2024_01_01_000006_create_permisos_usuarios_table.php
│   └── 2024_01_01_000008_add_fields_to_users_table.php
└── seeders/
    ├── DatabaseSeeder.php
    ├── TipoUsuarioSeeder.php
    ├── PermisoSeeder.php
    ├── RolSeeder.php
    └── UsuarioSeeder.php
```

---

## Función de Cada Carpeta

### `/app/Models/` - Modelos (Eloquent)

Representan las tablas de la base de datos. Cada modelo define:
- Nombre de la tabla
- Campos que se pueden llenar (`$fillable`)
- Relaciones con otros modelos

```php
// Ejemplo: app/Models/Cliente.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use SoftDeletes;

    protected $table = 'clientes';

    protected $fillable = [
        'nombre',
        'email',
        'telefono',
        'direccion',
    ];

    // Relación: Un cliente tiene muchos expedientes
    public function expedientes()
    {
        return $this->hasMany(Expediente::class, 'id_cliente', 'id');
    }
}
```

---

### `/app/Http/Controllers/` - Controladores

Manejan las peticiones HTTP. Contienen la lógica de cada endpoint (listar, ver, crear, actualizar, eliminar).

```php
// Ejemplo: app/Http/Controllers/ClientesController.php
namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Http\Requests\CreateClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientesController extends Controller
{
    // GET /api/clientes
    public function index(Request $request): JsonResponse
    {
        $query = Cliente::query();

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('nombre', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
        }

        $clientes = $query->orderBy('nombre')->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $clientes,
        ]);
    }

    // GET /api/clientes/{id}
    public function show($id): JsonResponse
    {
        $cliente = Cliente::with('expedientes')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $cliente,
        ]);
    }

    // POST /api/clientes
    public function store(CreateClienteRequest $request): JsonResponse
    {
        $cliente = Cliente::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Cliente creado correctamente',
            'data' => $cliente,
        ], 201);
    }

    // PUT /api/clientes/{id}
    public function update(UpdateClienteRequest $request, $id): JsonResponse
    {
        $cliente = Cliente::findOrFail($id);
        $cliente->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Cliente actualizado correctamente',
            'data' => $cliente,
        ]);
    }

    // DELETE /api/clientes/{id}
    public function destroy($id): JsonResponse
    {
        $cliente = Cliente::findOrFail($id);
        $cliente->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cliente eliminado correctamente',
        ]);
    }
}
```

---

### `/app/Http/Requests/` - Form Requests (Validaciones)

Validan los datos que llegan en las peticiones ANTES de que lleguen al controlador.

```php
// Ejemplo: app/Http/Requests/CreateClienteRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La autorización la maneja el middleware
    }

    public function rules(): array
    {
        return [
            'nombre' => 'required|string|max:255',
            'email' => 'required|email|unique:clientes,email',
            'telefono' => 'nullable|string|max:50',
            'direccion' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio',
            'email.required' => 'El email es obligatorio',
            'email.email' => 'El email debe ser válido',
            'email.unique' => 'El email ya está registrado',
        ];
    }
}
```

```php
// Ejemplo: app/Http/Requests/UpdateClienteRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $clienteId = $this->route('id');

        return [
            'nombre' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'email',
                Rule::unique('clientes', 'email')->ignore($clienteId),
            ],
            'telefono' => 'nullable|string|max:50',
            'direccion' => 'nullable|string|max:500',
        ];
    }
}
```

---

### `/app/Http/Middleware/` - Middlewares

Filtros que se ejecutan ANTES o DESPUÉS de las peticiones.

| Archivo | Función |
|---------|---------|
| `JwtMiddleware.php` | Verifica que el token JWT sea válido |
| `VerificarPermisos.php` | Verifica que el usuario tenga el permiso requerido |

---

### `/database/migrations/` - Migraciones

Definen la estructura de las tablas. Se ejecutan con `php artisan migrate`.

```php
// Ejemplo: database/migrations/2024_01_15_000001_create_clientes_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('email')->unique();
            $table->string('telefono', 50)->nullable();
            $table->text('direccion')->nullable();
            $table->timestamps();        // created_at, updated_at
            $table->softDeletes();       // deleted_at (para no borrar, solo marcar)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
```

**Crear migración:**
```bash
php artisan make:migration create_clientes_table
```

**Ejecutar migraciones:**
```bash
php artisan migrate
```

**Revertir última migración:**
```bash
php artisan migrate:rollback
```

---

### `/database/seeders/` - Seeders

Insertan datos iniciales en las tablas.

```php
// Ejemplo: database/seeders/ClienteSeeder.php
namespace Database\Seeders;

use App\Models\Cliente;
use Illuminate\Database\Seeder;

class ClienteSeeder extends Seeder
{
    public function run(): void
    {
        Cliente::firstOrCreate(
            ['email' => 'ejemplo@cliente.com'],
            [
                'nombre' => 'Cliente de Ejemplo',
                'telefono' => '123456789',
                'direccion' => 'Dirección de ejemplo',
            ]
        );
    }
}
```

**Ejecutar seeder:**
```bash
php artisan db:seed --class=ClienteSeeder
```

---

### `/routes/api.php` - Rutas de la API

Define los endpoints disponibles y qué controlador/método los maneja.

```php
// Ejemplo de rutas para Clientes
Route::prefix('clientes')->group(function () {
    Route::get('/', [ClientesController::class, 'index'])
        ->middleware('permisos.verify:list-clientes');
    Route::get('/{id}', [ClientesController::class, 'show'])
        ->middleware('permisos.verify:view-clientes');
    Route::post('/', [ClientesController::class, 'store'])
        ->middleware('permisos.verify:create-clientes');
    Route::put('/{id}', [ClientesController::class, 'update'])
        ->middleware('permisos.verify:update-clientes');
    Route::delete('/{id}', [ClientesController::class, 'destroy'])
        ->middleware('permisos.verify:delete-clientes');
});
```

---

## Guía: Agregar un Nuevo Módulo CRUD

Ejemplo completo para agregar el módulo "Clientes":

### Paso 1: Crear la Migración

```bash
php artisan make:migration create_clientes_table
```

Editar el archivo creado en `database/migrations/`:

```php
Schema::create('clientes', function (Blueprint $table) {
    $table->id();
    $table->string('nombre');
    $table->string('email')->unique();
    $table->string('telefono', 50)->nullable();
    $table->text('direccion')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

Ejecutar:
```bash
php artisan migrate
```

---

### Paso 2: Crear el Modelo

Crear `app/Models/Cliente.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use SoftDeletes;

    protected $table = 'clientes';

    protected $fillable = [
        'nombre',
        'email',
        'telefono',
        'direccion',
    ];
}
```

---

### Paso 3: Crear los Form Requests

Crear `app/Http/Requests/CreateClienteRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => 'required|string|max:255',
            'email' => 'required|email|unique:clientes,email',
            'telefono' => 'nullable|string|max:50',
            'direccion' => 'nullable|string',
        ];
    }
}
```

Crear `app/Http/Requests/UpdateClienteRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'email',
                Rule::unique('clientes', 'email')->ignore($this->route('id')),
            ],
            'telefono' => 'nullable|string|max:50',
            'direccion' => 'nullable|string',
        ];
    }
}
```

---

### Paso 4: Crear el Controlador

Crear `app/Http/Controllers/ClientesController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Http\Requests\CreateClienteRequest;
use App\Http\Requests\UpdateClienteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Cliente::query();

        if ($request->search) {
            $query->where('nombre', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
        }

        $clientes = $query->orderBy('nombre')->paginate($request->per_page ?? 15);

        return response()->json(['success' => true, 'data' => $clientes]);
    }

    public function show($id): JsonResponse
    {
        $cliente = Cliente::findOrFail($id);
        return response()->json(['success' => true, 'data' => $cliente]);
    }

    public function store(CreateClienteRequest $request): JsonResponse
    {
        $cliente = Cliente::create($request->validated());
        return response()->json([
            'success' => true,
            'message' => 'Cliente creado correctamente',
            'data' => $cliente
        ], 201);
    }

    public function update(UpdateClienteRequest $request, $id): JsonResponse
    {
        $cliente = Cliente::findOrFail($id);
        $cliente->update($request->validated());
        return response()->json([
            'success' => true,
            'message' => 'Cliente actualizado correctamente',
            'data' => $cliente
        ]);
    }

    public function destroy($id): JsonResponse
    {
        Cliente::findOrFail($id)->delete();
        return response()->json([
            'success' => true,
            'message' => 'Cliente eliminado correctamente'
        ]);
    }
}
```

---

### Paso 5: Agregar Permisos

Editar `database/seeders/PermisoSeeder.php` y agregar:

```php
// Clientes
['codigo' => 'list-clientes', 'nombre' => 'Listar clientes', 'grupo' => 'clientes'],
['codigo' => 'view-clientes', 'nombre' => 'Ver cliente', 'grupo' => 'clientes'],
['codigo' => 'create-clientes', 'nombre' => 'Crear cliente', 'grupo' => 'clientes'],
['codigo' => 'update-clientes', 'nombre' => 'Actualizar cliente', 'grupo' => 'clientes'],
['codigo' => 'delete-clientes', 'nombre' => 'Eliminar cliente', 'grupo' => 'clientes'],
```

Ejecutar:
```bash
php artisan db:seed --class=PermisoSeeder
```

---

### Paso 6: Agregar Rutas

Editar `routes/api.php` y agregar dentro del grupo JWT:

```php
use App\Http\Controllers\ClientesController;

// Dentro de Route::group(['middleware' => ['jwt.verify']], function () {

Route::prefix('clientes')->group(function () {
    Route::get('/', [ClientesController::class, 'index'])
        ->middleware('permisos.verify:list-clientes');
    Route::get('/{id}', [ClientesController::class, 'show'])
        ->middleware('permisos.verify:view-clientes');
    Route::post('/', [ClientesController::class, 'store'])
        ->middleware('permisos.verify:create-clientes');
    Route::put('/{id}', [ClientesController::class, 'update'])
        ->middleware('permisos.verify:update-clientes');
    Route::delete('/{id}', [ClientesController::class, 'destroy'])
        ->middleware('permisos.verify:delete-clientes');
});
```

---

### Paso 7: Asignar Permisos a Roles

Desde la API o directamente en la BD, asignar los nuevos permisos al rol "admin" (o ejecutar de nuevo el RolSeeder que asigna todos los permisos al admin).

```bash
php artisan db:seed --class=RolSeeder
```

---

## Resumen de Comandos Útiles

| Comando | Descripción |
|---------|-------------|
| `php artisan serve` | Iniciar servidor de desarrollo |
| `php artisan migrate` | Ejecutar migraciones pendientes |
| `php artisan migrate:rollback` | Revertir última migración |
| `php artisan migrate:fresh --seed` | Borrar todo y recrear con seeders |
| `php artisan db:seed` | Ejecutar todos los seeders |
| `php artisan db:seed --class=NombreSeeder` | Ejecutar seeder específico |
| `php artisan make:migration nombre` | Crear migración |
| `php artisan make:model Nombre` | Crear modelo |
| `php artisan make:controller NombreController` | Crear controlador |
| `php artisan make:request NombreRequest` | Crear form request |
| `php artisan route:list` | Ver todas las rutas |
| `php artisan cache:clear` | Limpiar caché |
| `php artisan config:clear` | Limpiar caché de config |

---

## Licencia

Este proyecto es software privado.
