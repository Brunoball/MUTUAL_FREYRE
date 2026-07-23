import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import {
  consultarSesionActual,
  solicitarCierreSesion,
  solicitarInicioSesion,
} from "../modules/login/inicioSesion.api";
import { clearStoredSession, readStoredSession, saveStoredSession } from "../shared/session";

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [session, setSession] = useState(() => readStoredSession());
  const [status, setStatus] = useState(session ? "authenticated" : "loading");

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
    let active = true;
    consultarSesionActual()
      .then((data) => {
        if (active) persist(data);
      })
      .catch((error) => {
        if (!active) return;
        if (error.status === 401 || !readStoredSession()) clear();
        else setStatus("authenticated");
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
    const data = await solicitarInicioSesion(credentials);
    persist(data);
    return data;
  }, [persist]);

  const logout = useCallback(async () => {
    try {
      await solicitarCierreSesion();
    } finally {
      clear();
    }
  }, [clear]);

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
    can,
  }), [session, status, login, logout, can]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) throw new Error("useAuth debe utilizarse dentro de AuthProvider.");
  return context;
}
