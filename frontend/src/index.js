import ReactDOM from "react-dom/client";
import App from "./App";
import { AuthProvider } from "./components/Login/AuthProvider";
import "./components/Global/Global_css/roots.css";
import "./components/Global/Global_css/Global.css";

const rootElement = document.getElementById("root");

if (!rootElement) {
  throw new Error('No se encontró el elemento raíz con id "root".');
}

const root = ReactDOM.createRoot(rootElement);

root.render(
  <AuthProvider>
    <App />
  </AuthProvider>
);
