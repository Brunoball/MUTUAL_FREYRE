import React from "react";
import { useAuth } from "../context/AuthProvider";
import ForbiddenPage from "../../Global/pantallas/ForbiddenPage";

export default function PermissionGate({ permission, children }) {
  const { can } = useAuth();
  return can(permission) ? children : <ForbiddenPage />;
}
