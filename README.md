![Nisaba](nisaba-banner.png)

# Lector de Feeds Pedagógico

Con la ayuda de IA de última generación, Nisaba te ayudará a **leer las noticias de una manera diferente**, al modo de un analista profesional. Nisaba te dará en un párrafo el marco apasionante del gran juego estratégico que da sentido al escenario geopolítico, económico, tecnológico y cultural. Luego lo reslacionará con las innovaciones y cambios disruptivos que podrían convertirre en nuevas tendencias y cambios globales en breve. Y finalmente te lo justificará señalándote los artículos en los que se basa y por qué.

Elige tus fuentes y agrega los medios de todo el mundo que más confianza te den. No te preocupes por la lengua,, la IA los traducirá por ti. 

Y recuerda: todo lo que la IA te presentará en sus informes está en las noticias con que la alimentas, sólo te muestra lo que el ruido no deja escuchar ni entender. Verás que la realidad global es mucho más apasionante que las banderías, las *fake news* y las *conspiranoias*.

## ¿Cómo usar Nisaba para descubrir sus posibilidades?

1. Tras configurar la app, sube  feeds (fuentes RSS o Atom) de periodicos y revistas de todo el mundo, no te preocupes por el idioma y ponlas en una misma carpeta, llámala por ejemplo «Noticias».
2. Actualiza las feeds pulsando el botón de arriba a la izquierda. Si has puesto muchas puede tardar un rato en bajarlas todas.
3. Pulsa el botón «Análisis. Te aparecerá un informe de análisis como los que utilizan los principales *think tanks* del mundo. Cada uno tiene un botón en la esquina superior derecha para que lo copies y lo guardes como quieras.
4. Pulsa el botón «Traducir Nuevos» y lee tranquilamente en español las noticias que más te interesen carpeta a carpeta o fuente a fuente. Puedes usar como guía el informe que preparó Gemini para ti y que leíste antes. En cada noticia podrás tomar notas que puedes compartir mediante RSS con otras personas, incorporar a tu blog o web personal o usar para programar envíos a redes sociales.

## Funcionalidades Principales

- **Agregación de Feeds**: Compatible con los formatos RSS y Atom.
- **Organización**: Agrupa tus feeds en carpetas temáticas para una mejor organización.
- **Importación/Exportación OPML**: Migra fácilmente tus suscripciones desde o hacia otros lectores de feeds.
- **Gestión de Artículos**: Marca artículos como leídos de forma individual o masiva.
- **Caché Configurable**: Elige si los artículos leídos se ocultan inmediatamente o si permanecen visibles (en gris) durante 24 o 48 horas antes de ser eliminados de la caché.
- **Traducción Automática**: Integra la API de Google Translate para traducir al español los artículos de feeds en otros idiomas.
- **Análisis con IA**: Utiliza la API de Google Gemini para generar análisis estratégicos y encontrar elementos  disruptivos y cambios de tendencias en tus artículos no leídos, agrupados por carpeta.
- **Notas Personales**: Toma notas sobre cualquier artículo o resumen. Tus notas se guardan y son accesibles en cualquier momento.
- **Feed de Notas**: Todas tus notas están disponibles a través de un feed RSS propio (`notas.xml`), permitiéndote suscribirte a tus propias ideas.
- **Interfaz Sencilla**: Una interfaz de usuario limpia y adaptable, centrada en la legibilidad.

## Instalación y Configuración

Sigue estos pasos para instalar y configurar Nisaba en tu propio servidor.

### 1. Requisitos Previos

Antes de empezar, asegúrate de que tu servidor cumple con los siguientes requisitos:

- **Servidor Web**: Un servidor web como Apache o Nginx con soporte para PHP 8.0 o superior.
- **Extensiones de PHP**:
    - `curl`: Necesaria para la traducción automática y para comunicarse con la API de Gemini.
    - `dom`: Para procesar los feeds RSS/Atom.
    - `simplexml`: El motor principal para leer y escribir los archivos de datos.
    - `mbstring`: Para el manejo correcto de caracteres multibyte.
- **Permisos de Escritura**: El servidor web debe tener permisos para escribir en el directorio `data/` de Nisaba.

### 2. Instalación

1.  **Descarga Nisaba**:
    - **Opción A (Git)**: Clona el repositorio en tu servidor. Ve al directorio donde quieres instalar Nisaba y ejecuta:
      ```sh
      git clone https://github.com/ciamaximalista/nisaba.git
      ```
    - **Opción B (ZIP)**: Descarga el archivo ZIP desde el [repositorio de GitHub](https://github.com/ciamaximalista/nisaba) y descomprímelo en el directorio de tu servidor web.

2.  **Configura los Permisos**:
    - Nisaba guarda todos sus datos (usuarios, feeds, caché) en el directorio `data/`. Es crucial que el servidor web tenga permiso para escribir en él.
    - Desde el directorio raíz de Nisaba, ejecuta el siguiente comando. Puede que necesites usar `sudo` dependiendo de la configuración de tu servidor:
      ```sh
      # Otorga permisos al grupo del servidor web (www-data es común en Debian/Ubuntu)
      chown -R :www-data data
      # Otorga permisos de escritura al grupo
      chmod -R 775 data
      ```
    - Si no estás seguro de cuál es el usuario de tu servidor web, puedes probar con `chmod -R 777 data`, aunque es una opción menos segura.

3.  **Primer Acceso y Creación de Usuario**:
    - Abre tu navegador y ve a la URL donde has instalado Nisaba (por ejemplo, `http://tu-dominio.com/nisaba/nisaba.php`).
    - La primera vez que accedas, se te presentará un formulario para crear la cuenta de administrador. Este será el único usuario de la aplicación.
    - Introduce un nombre de usuario y una contraseña segura y haz clic en "Registrar".

### 3. Configuración de las APIs de Google

Para desbloquear las funcionalidades de traducción e inteligencia artificial, necesitas configurar las APIs de Google.

1.  **Inicia Sesión** en tu instancia de Nisaba.
2.  En el menú de la izquierda, haz clic en **"Configuración y Preferencias"**.

#### a) API Key de Google Gemini (para Análisis con IA)

Esta API te permite usar la función "Análisis" para obtener resúmenes estratégicos de tus noticias.

1.  **Obtén tu API Key**:
    - Ve a [Google AI Studio](https://aistudio.google.com/app/apikey).
    - Inicia sesión con tu cuenta de Google.
    - Haz clic en "Create API key in new project".
    - Copia la clave que se genera.
2.  **Guárdala en Nisaba**:
    - En la página de configuración de Nisaba, pega la clave en el campo "API Key de Google Gemini".
    - Selecciona el modelo de Gemini que prefieras (por ejemplo, `gemini-1.5-pro-latest`).
    - Opcionalmente, puedes personalizar el *prompt* que se usa para generar los análisis para adaptarlo a tus intereses.

#### b) API Key de Google Translate (para Traducción)

Esta API te permite traducir artículos de otros idiomas al español.

1.  **Obtén tu API Key**:
    - Ve a la [Consola de Google Cloud](https://console.cloud.google.com/).
    - Crea un nuevo proyecto o selecciona uno existente.
    - En el menú de navegación, ve a "APIs y servicios" > "Biblioteca".
    - Busca "Cloud Translation API" y actívala para tu proyecto.
    - Ve a "APIs y servicios" > "Credenciales".
    - Haz clic en "Crear credenciales" > "Clave de API".
    - Copia la clave que se genera.
2.  **Guárdala en Nisaba**:
    - En la página de configuración de Nisaba, pega la clave en el campo "API Key de Google Translate".

3.  **Guarda la Configuración**:
    - Haz clic en el botón "Guardar Configuración" al final de la página.

¡Y ya está! Nisaba está listo para que empieces a añadir tus fuentes y a explorar las noticias con una nueva perspectiva.

### Uso Diario

- **Gestionar Fuentes**: Añade nuevas fuentes RSS o importa un archivo OPML desde la sección "Gestionar Fuentes". Aquí también puedes editar el nombre, la carpeta y el idioma de cada feed.
- **Actualizar Feeds**: Haz clic en "Actualizar Feeds" para descargar los últimos artículos de todas tus suscripciones.
- **Traducir y Analizar**: Usa los botones "Traducir Nuevos" y "Análisis" para procesar los artículos descargados.
- **Purgar Caché**: Si tienes configurada una duración de caché de 24h o 48h, el botón "Purgar artículos antiguos ahora" en la página de configuración eliminará manualmente los artículos que ya hayan expirado y que estarán apareciéndote de color gris.

## Licencia

Nisaba es software libre distribuido bajo la licencia **[EUPL v1.2](https://interoperable-europe.ec.europa.eu/collection/eupl/eupl-text-eupl-12)**.

## Autoría

Nisaba ha sido creado y es mantenido por **[Compañía Maximalista S.Coop.](https://maximalista.coop)**
