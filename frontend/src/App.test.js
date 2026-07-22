import { render, screen } from "@testing-library/react";
import App from "./app/App";
import { AuthProvider } from "./app/AuthProvider";
import { currentSessionRequest } from "./modules/auth/auth.api";

jest.mock("./modules/auth/auth.api", () => ({
  currentSessionRequest: jest.fn(),
  loginRequest: jest.fn(),
  logoutRequest: jest.fn(() => Promise.resolve()),
}));

beforeEach(() => {
  sessionStorage.clear();
  localStorage.clear();

  currentSessionRequest.mockReset();
  currentSessionRequest.mockRejectedValue(
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