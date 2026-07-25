import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import {
  consultarSesionActual,
  solicitarCierreSesion,
  solicitarInicioSesion,
} from "./api/inicioSesionApi";
import { clearStoredSession, readStoredSession, saveStoredSession } from "../_shared/session";

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [session, setSession] = useState(() => readStoredSession());
  const [status, setStatus] = useState("loading");
  const authRevision = useRef(0);

  const persist = useCallback((nextSession) => {
    setSession(nextSession);
    saveStoredSession(nextSession);
    setStatus("authenticated");
  }, []);

  const clear = useCallback(() => {
    clearStoredSession();
    setSession(null);
    setStatus("guest");
  }, []);

  useEffect(() => {
    const storedSession = readStoredSession();
    if (!storedSession) {
      setStatus("guest");
      return undefined;
    }

    let active = true;
    const revision = authRevision.current;
    consultarSesionActual()
      .then((data) => {
        if (!active || revision !== authRevision.current) return;
        if (data?.autenticado === false || !data?.usuario) {
          clear();
          return;
        }
        persist(data);
      })
      .catch((error) => {
        if (!active || revision !== authRevision.current) return;
        if (error.status === 401 || error.status === 403 || error.status === 423) {
          clear();
          return;
        }
        // Ante un problema temporal del servidor conserva la sesión local
        // para no expulsar al usuario por un fallo que no es de autenticación.
        setStatus("authenticated");
      });
    return () => {
      active = false;
    };
  }, [clear, persist]);

  useEffect(() => {
    const onUnauthorized = () => clear();
    window.addEventListener("mutual:unauthorized", onUnauthorized);
    return () => window.removeEventListener("mutual:unauthorized", onUnauthorized);
  }, [clear]);

  const login = useCallback(async (credentials) => {
    authRevision.current += 1;
    const data = await solicitarInicioSesion(credentials);
    persist(data);
    return data;
  }, [persist]);

  const logout = useCallback(async () => {
    authRevision.current += 1;
    try {
      await solicitarCierreSesion();
    } finally {
      clear();
    }
  }, [clear]);

  const refresh = useCallback(async () => {
    const data = await consultarSesionActual();
    persist(data);
    return data;
  }, [persist]);

  const can = useCallback((permission) => {
    if (!permission) return true;
    const permissions = session?.usuario?.permisos || [];
    return permissions.includes("*") || permissions.includes(permission);
  }, [session]);

  const value = useMemo(() => ({
    session,
    usuario: session?.usuario || null,
    status,
    isAuthenticated: status === "authenticated",
    login,
    logout,
    refresh,
    can,
  }), [session, status, login, logout, refresh, can]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) throw new Error("useAuth debe utilizarse dentro de AuthProvider.");
  return context;
}
