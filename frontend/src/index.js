import ReactDOM from "react-dom/client";
import App from "./app/App";
import { AuthProvider } from "./app/AuthProvider";
import "./Global/Global_css/roots.css";
import "./Global/Global_css/Global.css";

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
