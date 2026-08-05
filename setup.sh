#!/bin/bash

# Script de setup automático para Laravel Backend Template
# Uso: ./setup.sh nombre-proyecto

set -e

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Función para imprimir mensajes
print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

print_info() {
    echo -e "${YELLOW}→${NC} $1"
}

# Verificar que se proporcionó el nombre del proyecto
if [ -z "$1" ]; then
    print_error "Debe proporcionar un nombre para el proyecto"
    echo "Uso: ./setup.sh nombre-proyecto"
    exit 1
fi

PROJECT_NAME=$1
CURRENT_DIR=$(pwd)

print_info "Configurando nuevo proyecto: $PROJECT_NAME"
echo ""

# 1. Copiar plantilla
print_info "Copiando plantilla..."
cp -r "$CURRENT_DIR" "../$PROJECT_NAME"
cd "../$PROJECT_NAME"
print_success "Plantilla copiada"

# 2. Remover archivos de setup
rm -f setup.sh README-TEMPLATE.md
print_success "Archivos de setup removidos"

# 3. Copiar .env.example a .env
print_info "Configurando archivo .env..."
cp .env.example .env
print_success "Archivo .env creado"

# 4. Instalar dependencias de Composer
print_info "Instalando dependencias de PHP (esto puede tomar unos minutos)..."
if command -v composer &> /dev/null; then
    composer install --no-interaction
    print_success "Dependencias de PHP instaladas"
else
    print_error "Composer no está instalado. Instálalo desde https://getcomposer.org"
    exit 1
fi

# 5. Instalar dependencias de NPM
print_info "Instalando dependencias de Node..."
if command -v npm &> /dev/null; then
    npm install
    print_success "Dependencias de Node instaladas"
else
    print_error "NPM no está instalado. Instálalo desde https://nodejs.org"
    exit 1
fi

# 6. Generar APP_KEY
print_info "Generando Application Key..."
php artisan key:generate
print_success "Application Key generada"

# 7. Generar JWT_SECRET
print_info "Generando JWT Secret..."
php artisan jwt:secret
print_success "JWT Secret generado"

# 8. Crear base de datos (opcional)
echo ""
read -p "¿Deseas crear la base de datos ahora? (y/n) " -n 1 -r
echo ""
if [[ $REPLY =~ ^[Yy]$ ]]; then
    read -p "Nombre de la base de datos: " DB_NAME
    read -p "Usuario de MySQL: " DB_USER
    read -sp "Contraseña de MySQL: " DB_PASS
    echo ""
    
    mysql -u$DB_USER -p$DB_PASS -e "CREATE DATABASE IF NOT EXISTS $DB_NAME;"
    
    # Actualizar .env
    sed -i "s/DB_DATABASE=.*/DB_DATABASE=$DB_NAME/" .env
    sed -i "s/DB_USERNAME=.*/DB_USERNAME=$DB_USER/" .env
    sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=$DB_PASS/" .env
    
    print_success "Base de datos creada y configurada"
    
    # 9. Ejecutar migraciones
    read -p "¿Ejecutar migraciones? (y/n) " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        php artisan migrate
        print_success "Migraciones ejecutadas"
    fi
fi

# 10. Configurar permisos
print_info "Configurando permisos..."
chmod -R 775 storage bootstrap/cache
print_success "Permisos configurados"

# Resumen final
echo ""
echo "=========================================="
print_success "¡Proyecto $PROJECT_NAME configurado exitosamente!"
echo "=========================================="
echo ""
echo "Próximos pasos:"
echo "1. cd ../$PROJECT_NAME"
echo "2. Edita .env con tus configuraciones"
echo "3. php artisan serve"
echo "4. npm run dev"
echo ""
echo "API disponible en: http://localhost:8000/api"
echo ""
