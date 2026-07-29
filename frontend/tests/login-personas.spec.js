import { test, expect } from '@playwright/test';

test('login, navegación y modales de Personas sin errores', async ({ page }) => {
  const erroresBackend = [];
  const erroresNavegador = [];

  // Registra respuestas graves del backend.
  page.on('response', (response) => {
    const url = response.url();
    const status = response.status();

    const esBackend =
      url.includes('localhost:3001') ||
      url.includes('/api/');

    // Por ahora controlamos errores 500+, evitando falsos positivos
    // por respuestas 401 esperables antes de iniciar sesión.
    if (esBackend && status >= 500) {
      erroresBackend.push({
        estado: status,
        metodo: response.request().method(),
        url,
      });
    }
  });

  // Detecta peticiones que no lograron conectarse.
  page.on('requestfailed', (request) => {
    const url = request.url();

    const esBackend =
      url.includes('localhost:3001') ||
      url.includes('/api/');

    if (esBackend) {
      erroresBackend.push({
        estado: 'REQUEST_FAILED',
        metodo: request.method(),
        url,
        detalle: request.failure()?.errorText || 'Error desconocido',
      });
    }
  });

  // Registra errores JavaScript producidos dentro de la aplicación.
  page.on('pageerror', (error) => {
    erroresNavegador.push(error.message);
  });

  await page.goto('http://localhost:3000/');

  // Login.
  await page.getByLabel('').first().fill('bruno');

  await page
    .getByRole('textbox', { name: 'Mostrar contraseña' })
    .fill('1234');

  await page
    .getByRole('button', { name: 'Ingresar al sistema' })
    .click();

  // Confirma que el login realmente funcionó.
  await expect(page).toHaveURL(/\/panel/);

  await expect(
    page.getByText('Panel general', { exact: true })
  ).toBeVisible();

  // Entra a Personas.
  await page
    .getByRole('link', { name: 'Personas y asociados' })
    .click();

  // Confirma que la sección cargó.
  await expect(page).toHaveURL(/personas/i);

  // Abre el modal de información.
  await page
    .getByRole('button', { name: 'Ver información de ACOSTA,' })
    .click();

  const botonCerrar = page.getByRole('button', {
    name: 'Cerrar',
    exact: true,
  });

  await expect(botonCerrar).toBeVisible();

  // Cierra el modal usando la X o botón Cerrar.
  await botonCerrar.click();

  await expect(botonCerrar).toBeHidden();

  // Abre el modal de edición.
  await page
    .getByRole('button', {
      name: 'Editar ficha de ACOSTA, TOMÁS',
    })
    .click();

  const modalEdicion = page.getByRole('dialog');

  await expect(modalEdicion).toBeVisible();

  // Cierra el modal usando Escape.
  await page.keyboard.press('Escape');

  await expect(modalEdicion).toBeHidden();

  // Hace fallar la prueba si se detectó algún error del backend.
  expect(
    erroresBackend,
    `Se detectaron errores del backend:\n${JSON.stringify(
      erroresBackend,
      null,
      2
    )}`
  ).toEqual([]);

  // Hace fallar la prueba si React/JavaScript produjo errores.
  expect(
    erroresNavegador,
    `Se detectaron errores JavaScript:\n${erroresNavegador.join('\n')}`
  ).toEqual([]);
});