import { render, screen } from "@testing-library/react";
import App from "./App";
import { AuthProvider } from "./components/Login/context/AuthProvider";
import { consultarSesionActual } from "./components/Login/api/inicioSesionApi";

jest.mock("./components/Login/api/inicioSesionApi", () => ({
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

test("muestra el acceso institucional sin consultar auth/me cuando no existe una sesión", async () => {
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
  expect(consultarSesionActual).not.toHaveBeenCalled();
});
