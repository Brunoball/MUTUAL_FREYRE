import { render, screen } from "@testing-library/react";
import App from "./app/App";
import { AuthProvider } from "./app/AuthProvider";
import { consultarSesionActual } from "./modules/login/inicioSesion.api";

jest.mock("./modules/login/inicioSesion.api", () => ({
  consultarSesionActual: jest.fn(),
  solicitarInicioSesion: jest.fn(),
  solicitarCierreSesion: jest.fn(() => Promise.resolve()),
}));

beforeEach(() => {
  sessionStorage.clear();
  localStorage.clear();

  consultarSesionActual.mockReset();
  consultarSesionActual.mockRejectedValue(
    Object.assign(new Error("Sin sesión"), {
      status: 401,
    })
  );
});

test("muestra el acceso institucional cuando no existe una sesión", async () => {
  render(
    <AuthProvider>
      <App />
    </AuthProvider>
  );

  expect(
    await screen.findByRole("heading", {
      name: /iniciar sesión/i,
    })
  ).toBeInTheDocument();
});
