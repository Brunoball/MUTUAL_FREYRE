import React, { useMemo, useState } from "react";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faEye, faEyeSlash, faLock, faUser } from "@fortawesome/free-solid-svg-icons";
import { useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../../app/AuthProvider";
import logoPerfilTitleSf from "../../assets/images/logo_perfil_title_sf.png";

const REMEMBER_KEY = "mutual_freyre_remembered_user";

function rememberedUsername() {
  try { return localStorage.getItem(REMEMBER_KEY) || ""; } catch { return ""; }
}

export default function LoginPage() {
  const auth = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const initialUser = useMemo(rememberedUsername, []);
  const [usuario, setUsuario] = useState(initialUser);
  const [contrasena, setContrasena] = useState("");
  const [remember, setRemember] = useState(Boolean(initialUser));
  const [visible, setVisible] = useState(false);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");

  const submit = async (event) => {
    event.preventDefault();
    setMessage("");
    setLoading(true);
    try {
      await auth.login({ usuario: usuario.trim(), contrasena });
      try {
        if (remember) localStorage.setItem(REMEMBER_KEY, usuario.trim());
        else localStorage.removeItem(REMEMBER_KEY);
      } catch { /* opcional */ }
      navigate(location.state?.from?.pathname || "/panel", { replace: true });
    } catch (error) {
      setMessage(error.message || "No se pudo iniciar sesión.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <main className="login-page">
      <section className="login-brand">
        <div className="login-brand__identity">
          <img src={logoPerfilTitleSf} alt="Perfil SF" />
        </div>
        <p className="login-brand__copy">Gestión centralizada de asociados, ayudas económicas, ahorros, valores, caja, bancos y contabilidad.</p>
        <div className="login-brand__principles">
          <span>Una sola institución</span><span>Arquitectura modular</span><span>Operaciones auditables</span>
        </div>
      </section>

      <section className="login-access">
        <div className="login-card">
          <header><p>Acceso al backoffice</p><h2>Iniciar sesión</h2><span>Ingresá tus credenciales institucionales.</span></header>
          <form onSubmit={submit}>
            <label className="login-field">
              <span>Usuario</span>
              <div><FontAwesomeIcon icon={faUser} /><input value={usuario} onChange={(e) => setUsuario(e.target.value)} autoComplete="username" maxLength={100} required autoFocus /></div>
            </label>
            <label className="login-field">
              <span>Contraseña</span>
              <div><FontAwesomeIcon icon={faLock} /><input type={visible ? "text" : "password"} value={contrasena} onChange={(e) => setContrasena(e.target.value)} autoComplete="current-password" maxLength={255} required />
                <button type="button" onClick={() => setVisible((value) => !value)} aria-label={visible ? "Ocultar contraseña" : "Mostrar contraseña"}><FontAwesomeIcon icon={visible ? faEyeSlash : faEye} /></button>
              </div>
            </label>
            <label className="login-remember"><input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} /><span>Recordar solamente el usuario</span></label>
            {message ? <div className="login-error" role="alert">{message}</div> : null}
            <button className="login-submit" type="submit" disabled={loading}>{loading ? "Verificando..." : "Ingresar al sistema"}</button>
          </form>
          <footer>La sesión se valida en el servidor y no se guardan credenciales en el navegador.</footer>
        </div>
      </section>
    </main>
  );
}
