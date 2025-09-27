# Nisaba Feed Reader

Nisaba es un lector de feeds RSS/Atom personal y auto-alojado, diseñado para ofrecer una experiencia de lectura limpia y potente. Además de las funcionalidades estándar de un agregador de noticias, Nisaba integra herramientas avanzadas de IA para el análisis y la traducción de contenidos.

## Funcionalidades Principales

- **Agregación de Feeds**: Compatible con los formatos RSS y Atom.
- **Organización**: Agrupa tus feeds en carpetas temáticas para una mejor organización.
- **Importación/Exportación OPML**: Migra fácilmente tus suscripciones desde o hacia otros lectores de feeds.
- **Gestión de Artículos**: Marca artículos como leídos de forma individual o masiva.
- **Caché Configurable**: Elige si los artículos leídos se ocultan inmediatamente o si permanecen visibles (en gris) durante 24 o 48 horas antes de ser eliminados de la caché.
- **Traducción Automática**: Integra la API de Google Translate para traducir al español los artículos de feeds en otros idiomas.
- **Análisis con IA**: Utiliza la API de Google Gemini para generar resúmenes y análisis estratégicos de tus artículos no leídos, agrupados por carpeta.
- **Notas Personales**: Toma notas sobre cualquier artículo o resumen. Tus notas se guardan y son accesibles en cualquier momento.
- **Feed de Notas**: Todas tus notas están disponibles a través de un feed RSS propio (`notas.xml`), permitiéndote suscribirte a tus propias ideas.
- **Interfaz Sencilla**: Una interfaz de usuario limpia y adaptable, centrada en la legibilidad.

## Instalación

### Requisitos

- Un servidor web con soporte para PHP (ej. Apache, Nginx).
- La extensión cURL de PHP habilitada (necesaria para la función de traducción).
- Permisos de escritura para el servidor web en el directorio `data/` y sus subdirectorios.

### Pasos de Instalación

1.  **Descargar/Clonar**: Descarga los archivos de Nisaba o clona el repositorio en un directorio accesible por tu servidor web.
2.  **Establecer Permisos**: Asegúrate de que los directorios `data/` y `data/favicons/` existen y de que el usuario del servidor web (normalmente `www-data`) tiene permisos de escritura sobre ellos. Puedes hacerlo con el siguiente comando desde el directorio raíz de Nisaba:
    ```sh
    chmod -R 775 data
    ```
3.  **Primer Acceso**: Abre `nisaba.php` en tu navegador web (ej. `http://tuservidor.com/nisaba/nisaba.php`).
4.  **Crear Administrador**: La primera vez que accedas, Nisaba te pedirá que crees la cuenta de administrador. Introduce un nombre de usuario y una contraseña.
5.  **Iniciar Sesión**: Una vez creada la cuenta, inicia sesión con tus credenciales.

## Configuración y Uso

Tras iniciar sesión, se recomienda configurar las integraciones con las APIs de Google para habilitar las funcionalidades avanzadas.

1.  **Navega a "Configuración y Preferencias"** en la barra lateral.

2.  **API Key de Google Gemini**: Para usar la función de "Análisis", necesitas una API Key de Google Gemini. Puedes obtener una de forma gratuita en [Google AI Studio](https://aistudio.google.com/app/apikey).

3.  **API Key de Google Translate**: Para usar la función de "Traducir Nuevos", necesitas una API Key de la API de Google Cloud Translation. Puedes obtenerla creando un proyecto en la [Consola de Google Cloud](https://console.cloud.google.com/).

4.  **Otros Ajustes**:
    - **Modelo de Gemini**: Selecciona el modelo de IA que prefieras para los análisis.
    - **Prompt para Gemini**: Puedes personalizar el prompt que se envía a la IA para adaptar los resúmenes a tus necesidades.
    - **Borrar artículos leídos de la caché**: Configura cuándo deben desaparecer los artículos leídos (inmediatamente, a las 24h o a las 48h).
    - **Artículos leídos**: Decide si los artículos leídos permanecen visibles (en gris) hasta que se purgan de la caché o si se ocultan en cuanto los marcas como leídos.

### Uso Diario

- **Gestionar Fuentes**: Añade nuevas fuentes RSS o importa un archivo OPML desde la sección "Gestionar Fuentes". Aquí también puedes editar el nombre, la carpeta y el idioma de cada feed.
- **Actualizar Feeds**: Haz clic en "Actualizar Feeds" para descargar los últimos artículos de todas tus suscripciones.
- **Traducir y Analizar**: Usa los botones "Traducir Nuevos" y "Análisis" para procesar los artículos descargados.
- **Purgar Caché**: Si tienes configurada una duración de caché de 24h o 48h, el botón "Purgar artículos antiguos ahora" en la página de configuración eliminará manualmente los artículos que ya hayan expirado.

## Licencia

Nisaba es software libre distribuido bajo la licencia **EUPL v1.2**.

## Autoría

Nisaba ha sido creado y es mantenido por **Compañía Maximalista S.Coop.**
