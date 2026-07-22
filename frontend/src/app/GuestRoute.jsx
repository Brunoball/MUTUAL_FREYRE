import React from "react";
import { Navigate, Outlet } from "react-router-dom";
import { useAuth } from "./AuthProvider";
import LoadingScreen from "../shared/LoadingScreen";

export default function GuestRoute() {
  const auth = useAuth();
  if (auth.status === "loading") return <LoadingScreen text="Cargando acceso..." />;
  return auth.isAuthenticated ? <Navigate to="/panel" replace /> : <Outlet />;
}
